<?php

namespace Tests\Feature;

use App\Models\EntityRelation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\SemanticRelations;
use App\Services\SemanticTechniqueEdges;
use App\Services\TechniqueMerger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 後台新增、刪除上下位與相關（`SemanticTechniqueEdges`）和合併技術（`TechniqueMerger`）拿同一組鎖、同一個順序：
 * 先鎖 broader／narrower／related 三個述詞，再鎖牽涉的兩筆技術。
 *
 * 合併只鎖自己兩筆技術的話，跟一次兩端不重疊的新增可以同時各自檢查通過、合起來違反詞表規則；刪除不鎖技術的話，
 * 合併剛改接到別的技術上的那一列可能被照舊的 id 刪掉。SQLite 沒有列鎖，沒辦法真的讓兩個 transaction 互等，
 * 所以這裡看實際送出的查詢：鎖述詞的查詢一定在鎖技術之前、三種寫入送的是同一句，PostgreSQL 上兩句都帶 FOR UPDATE。
 */
class TechniqueEdgeLockOrderTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Technique> */
    private array $t = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $concept = Scope::where('name', 'concept')->value('id');
        foreach (['雲端服務', 'PaaS', 'PaaS（舊）', 'SPA'] as $title) {
            $this->t[$title] = Technique::create(['type' => $concept, 'title' => $title]);
        }
        EntityRelation::create([
            'entity_type' => 'technique',
            'subject_id' => $this->t['PaaS']->id,
            'object_id' => $this->t['雲端服務']->id,
            'relation_id' => Relation::where('name', 'broader')->value('id'),
        ]);
    }

    /**
     * 跑 $write，回傳期間送出的查詢（SQL、參數）。
     *
     * @return list<array{0: string, 1: array<int, mixed>}>
     */
    private function queriesDuring(callable $write): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = [$query->sql, $query->bindings];
        });

        $write();

        return $queries;
    }

    private function isPredicateLock(string $sql): bool
    {
        return str_contains($sql, 'from "relations" where "name" in');
    }

    private function isTechniqueLock(string $sql): bool
    {
        return str_contains($sql, 'from "techniques" where "techniques"."id" in');
    }

    /** @return iterable<string, array{string}> */
    public static function writes(): iterable
    {
        yield '新增上下位' => ['add'];
        yield '刪除上下位' => ['remove'];
        yield '合併技術' => ['merge'];
    }

    /** @return array{0: callable, 1: list<int>} 要跑的寫入、它該鎖的兩筆技術 */
    private function write(string $write): array
    {
        $edges = app(SemanticTechniqueEdges::class);

        return match ($write) {
            'add' => [fn () => $edges->add(SemanticRelations::BROADER, $this->t['SPA'], $this->t['雲端服務']), [$this->t['雲端服務']->id, $this->t['SPA']->id]],
            'remove' => [fn () => $edges->remove(SemanticRelations::BROADER, $this->t['PaaS'], $this->t['雲端服務']), [$this->t['雲端服務']->id, $this->t['PaaS']->id]],
            'merge' => [fn () => app(TechniqueMerger::class)->merge($this->t['PaaS（舊）'], $this->t['PaaS']), [$this->t['PaaS']->id, $this->t['PaaS（舊）']->id]],
        };
    }

    #[DataProvider('writes')]
    #[TestDox('先鎖三個述詞，再鎖兩筆技術，之前不碰技術的鎖')]
    public function test_the_predicate_lock_comes_before_the_technique_lock(string $write): void
    {
        [$run, $techniqueIds] = $this->write($write);
        sort($techniqueIds);

        $queries = $this->queriesDuring($run);
        $predicateLocks = array_keys(array_filter($queries, fn (array $q) => $this->isPredicateLock($q[0])));
        $techniqueLocks = array_keys(array_filter($queries, fn (array $q) => $this->isTechniqueLock($q[0])));

        $this->assertCount(1, $predicateLocks);
        $this->assertCount(1, $techniqueLocks);
        $this->assertLessThan($techniqueLocks[0], $predicateLocks[0]);
        $this->assertSame(['broader', 'narrower', 'related'], $queries[$predicateLocks[0]][1]);
        // whereKey() 收整數陣列時直接把 id 寫進 SQL（whereIntegerInRaw），不走參數
        preg_match('/"techniques"\."id" in \(([^)]*)\)/', $queries[$techniqueLocks[0]][0], $match);
        $locked = array_map('intval', explode(',', $match[1] ?? ''));
        sort($locked);
        $this->assertSame($techniqueIds, $locked);
        // 兩句都依 id 排序；PostgreSQL 上真的是列鎖（SQLite 的語法沒有 FOR UPDATE，Laravel 不輸出）
        foreach ([$predicateLocks[0], $techniqueLocks[0]] as $index) {
            $this->assertStringContainsString('order by "id" asc', $queries[$index][0]);
            if (DB::getDriverName() === 'pgsql') {
                $this->assertStringEndsWith('for update', $queries[$index][0]);
            }
        }
    }

    #[TestDox('新增、刪除與合併送出的述詞鎖是同一句')]
    public function test_every_write_takes_the_same_predicate_lock(): void
    {
        $locks = [];
        foreach (['add', 'remove', 'merge'] as $write) {
            [$run] = $this->write($write);
            $locks[$write] = array_values(array_filter($this->queriesDuring($run), fn (array $q) => $this->isPredicateLock($q[0])));
        }

        $this->assertCount(1, $locks['add']);
        $this->assertSame($locks['add'], $locks['remove']);
        $this->assertSame($locks['add'], $locks['merge']);
    }

    #[TestDox('刪除時另一端在綁定之後被刪除：鎖住重讀後回 404，邊不動')]
    public function test_remove_rereads_the_ends_after_locking(): void
    {
        $stale = Technique::findOrFail($this->t['雲端服務']->id);
        Technique::whereKey($this->t['雲端服務']->id)->delete();

        try {
            app(SemanticTechniqueEdges::class)->remove(SemanticRelations::BROADER, $this->t['PaaS'], $stale);
            $this->fail('另一端已刪除，應該回 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(1, EntityRelation::where(['subject_id' => $this->t['PaaS']->id, 'object_id' => $this->t['雲端服務']->id])->count());
    }
}
