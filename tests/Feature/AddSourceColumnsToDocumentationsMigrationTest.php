<?php

namespace Tests\Feature;

use App\Models\Documentation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 2026_11_12_000000_add_source_columns_to_documentations_table 與
 * 2026_11_12_000001_add_source_unique_index_to_documentations_table：文章的匯入來源欄位，
 * 以及 (source_repo, source_key) 的唯一索引。
 *
 * 唯一索引的幾個重點在 SQLite 與 PostgreSQL 上都要成立（CI 兩種都跑）：
 * 沒有來源（NULL）的文章不會互相衝突；同一組來源只能有一筆，已軟刪除的那筆也算。
 */
class AddSourceColumnsToDocumentationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_COLUMNS = [
        'source_repo',
        'source_key',
        'source_path',
        'source_blob_sha',
        'source_commit_sha',
        'source_imported_at',
        'source_body_hash',
    ];

    /** down() 不能動到的既有欄位。 */
    private const ORIGINAL_COLUMNS = [
        'id', 'type', 'title', 'url', 'uri', 'body', 'note', 'status',
        'creation_date', 'deleted_at', 'created_at', 'updated_at',
    ];

    #[TestDox('七個來源欄位都存在，而且都可以是 NULL')]
    public function test_every_source_column_exists_and_is_nullable(): void
    {
        $columns = collect(Schema::getColumns('documentations'))->keyBy('name');

        foreach (self::SOURCE_COLUMNS as $name) {
            $this->assertTrue($columns->has($name), "少了 {$name}");
            $this->assertTrue($columns[$name]['nullable'], "{$name} 應該可以是 NULL");
        }

        // 手寫的文章不帶任何來源欄位，照常建立
        $documentation = Documentation::factory()->create();
        foreach (self::SOURCE_COLUMNS as $name) {
            $this->assertNull($documentation->fresh()->{$name});
        }
    }

    /** @return iterable<string, array{string|null, string|null}> */
    public static function sourcePairsThatMayRepeat(): iterable
    {
        yield '兩欄都是 NULL' => [null, null];
        yield '只有 repo' => ['example/articles', null];
        yield '只有 key' => [null, 'my-post'];
    }

    #[DataProvider('sourcePairsThatMayRepeat')]
    #[TestDox('來源有 NULL 的文章可以有很多筆，不會撞唯一索引')]
    public function test_rows_with_a_null_source_column_never_collide(?string $repo, ?string $key): void
    {
        Documentation::factory()->create(['source_repo' => $repo, 'source_key' => $key]);
        Documentation::factory()->create(['source_repo' => $repo, 'source_key' => $key]);

        $this->assertSame(2, Documentation::where('source_repo', $repo)->where('source_key', $key)->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function existingRowStates(): iterable
    {
        yield '既有的那筆還在' => [false];
        yield '既有的那筆已軟刪除' => [true];
    }

    #[DataProvider('existingRowStates')]
    #[TestDox('同一組 repo 與 key 只能有一筆，已軟刪除的也算')]
    public function test_a_duplicate_repo_and_key_pair_is_rejected(bool $existingIsTrashed): void
    {
        $existing = Documentation::factory()->create(['source_repo' => 'example/articles', 'source_key' => 'my-post']);
        if ($existingIsTrashed) {
            $existing->delete();
            $this->assertSoftDeleted($existing);
        }

        $this->assertRejectedAsDuplicate(['source_repo' => 'example/articles', 'source_key' => 'my-post']);
        $this->assertSame(1, Documentation::withTrashed()->where('source_key', 'my-post')->count());
    }

    #[TestDox('repo 或 key 只要有一個不同，就可以並存')]
    public function test_pairs_that_differ_in_repo_or_key_coexist(): void
    {
        Documentation::factory()->create(['source_repo' => 'example/articles', 'source_key' => 'my-post']);
        Documentation::factory()->create(['source_repo' => 'example/articles', 'source_key' => 'my-post-2']);
        Documentation::factory()->create(['source_repo' => 'example/other', 'source_key' => 'my-post']);

        $this->assertSame(3, Documentation::whereNotNull('source_key')->count());
    }

    #[TestDox('down() 只拿掉索引與來源欄位，文章列與既有欄位不變')]
    public function test_down_drops_only_the_index_and_the_source_columns(): void
    {
        $columnsMigration = require database_path('migrations/2026_11_12_000000_add_source_columns_to_documentations_table.php');
        $indexMigration = require database_path('migrations/2026_11_12_000001_add_source_unique_index_to_documentations_table.php');

        Documentation::factory()->withBody()->create([
            'source_repo' => 'example/articles',
            'source_key' => 'my-post',
            'source_path' => 'posts/my-post.md',
            'source_blob_sha' => str_repeat('a', 40),
            'source_commit_sha' => str_repeat('b', 40),
            'source_imported_at' => '2026-11-12 08:00:00',
            'source_body_hash' => str_repeat('c', 64),
        ]);
        Documentation::factory()->create(['status' => 0]);
        Documentation::factory()->create()->delete();
        $before = $this->originalColumnsSnapshot();
        $this->assertCount(3, $before);

        // 依 migrate:rollback 的順序：後跑的先退
        $indexMigration->down();
        $this->assertFalse(Schema::hasIndex('documentations', ['source_repo', 'source_key'], 'unique'));
        $columnsMigration->down();

        foreach (self::SOURCE_COLUMNS as $name) {
            $this->assertFalse(Schema::hasColumn('documentations', $name), "{$name} 應該被拿掉");
        }
        $this->assertTrue(Schema::hasColumns('documentations', self::ORIGINAL_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());

        // 欄位加回來，既有的列一樣不變。索引那支用 CREATE INDEX CONCURRENTLY，
        // 不能在測試的 transaction 裡跑；它的 up() 由每次測試前的 migrate 跑過（兩種資料庫都是）。
        $columnsMigration->up();
        $this->assertTrue(Schema::hasColumns('documentations', self::SOURCE_COLUMNS));
        $this->assertEquals($before, $this->originalColumnsSnapshot());
    }

    /**
     * 包在巢狀 transaction（savepoint）裡：PostgreSQL 上一句失敗會讓整個 transaction 失效，
     * 不先退回 savepoint，後面的查詢都會失敗。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertRejectedAsDuplicate(array $attributes): void
    {
        try {
            DB::transaction(fn () => Documentation::factory()->create($attributes));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $this->fail('重複的 source_repo＋source_key 應該被唯一索引擋下');
    }

    /** @return list<array<string, mixed>> */
    private function originalColumnsSnapshot(): array
    {
        return DB::table('documentations')
            ->orderBy('id')
            ->get(self::ORIGINAL_COLUMNS)
            ->map(fn (object $row) => (array) $row)
            ->all();
    }
}
