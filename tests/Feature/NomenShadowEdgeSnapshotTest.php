<?php

namespace Tests\Feature;

use App\Models\Nomen;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * 影子模式（nomens 表只記錄、不改邊）的守門測試：同步與文章匯入寫出的節點與邊，
 * 要跟加上 nomens 之前的程式一模一樣。
 *
 * `snapshots/nomen-shadow-edges.json` 是在加上 nomens 之前的程式上跑同一段流程產生的，
 * 之後不要為了讓測試變綠而重新產生：這個檔案對不上，就表示影子寫入改到了既有的邊。
 * 只用自然鍵（標題、版本、git_repo_id、述詞名稱）比對，不比 id：PostgreSQL 的流水號在
 * `RefreshDatabase` 回滾後不會重設，id 在 SQLite 與 PostgreSQL 上會不同。
 */
class NomenShadowEdgeSnapshotTest extends TestCase
{
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    private const SNAPSHOT = __DIR__.'/snapshots/nomen-shadow-edges.json';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[TestDox('同步兩次加匯入文章後，節點與邊跟加上 nomens 之前完全相同')]
    public function test_sync_and_article_import_write_the_same_nodes_and_edges_as_before(): void
    {
        $this->seed();

        Carbon::setTestNow('2026-11-01 03:17:00');
        (new SaveReposDataService($this->repos()))->save_repos_data();
        Carbon::setTestNow('2026-11-02 03:17:00');
        (new SaveReposDataService($this->repos()))->save_repos_data();

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->configureArticleSource();
        $this->fakeGitHub();
        $content = self::article('post-a', '影子文章', extra: [
            'about_techniques' => '[vuejs, laravel, "Vue 3", "Next.js", 伺服器端渲染]',
            'about_projects' => '[shadow-a, isbn-scanner, laravel]',
        ]);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();
        $this->postJson('/api/article-sources/import', [
            'commit_sha' => $commit,
            'items' => [['key' => 'post-a', 'expected_blob_sha' => self::blobSha($content), 'action' => 'create']],
        ])->assertOk();

        $actual = json_encode($this->graphSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $this->assertSame(file_get_contents(self::SNAPSHOT), $actual);
        // 影子寫入真的有跑（語言、topic、文章兩個欄位都有列），上面的比對才有意義
        $this->assertEqualsCanonicalizing(
            [Nomen::SOURCE_GITHUB_LANGUAGE, Nomen::SOURCE_GITHUB_TOPIC, Nomen::SOURCE_ABOUT_TECHNIQUES, Nomen::SOURCE_ABOUT_PROJECTS],
            Nomen::distinct()->pluck('source')->all(),
        );
    }

    /**
     * 正式資料的快照，再加兩個涵蓋各條路徑的 repo：被略過的語言（Hack）、對照表（Procfile、vue3、nuxt3）、
     * 結尾是數字的套件名（isbn3、html5-qrcode），以及語言的位元組數。
     */
    private function repos()
    {
        $snapshot = json_decode(file_get_contents(database_path('seeders/fixtures/github-repos-snapshot.json')), true);

        return collect($snapshot['repos'])->concat([
            $this->repo(900001, 'shadow-a', ['PHP' => 50000, 'Hack' => 2000, 'Procfile' => 30, 'Vue' => 9000], ['vue3', 'laravel', 'isbn3', 'html5-qrcode', 'database']),
            $this->repo(900002, 'shadow-b', ['JavaScript' => 120000, 'Shell' => 130], ['vuejs', 'nuxt3', 'tailwind']),
        ]);
    }

    /**
     * @param  array<string, int>  $bytes
     * @param  list<string>  $topics
     * @return array<string, mixed>
     */
    private function repo(int $id, string $name, array $bytes, array $topics): array
    {
        return [
            'id' => $id,
            'git_repo_id' => $id,
            'title' => $name,
            'html_url' => "https://github.com/example/{$name}",
            'description' => null,
            'topics' => $topics,
            'languages' => array_keys($bytes),
            'language_bytes' => $bytes,
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'example',
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function graphSnapshot(): array
    {
        $scopes = DB::table('scopes')->pluck('name', 'id')->all();
        $relations = DB::table('relations')->pluck('name', 'id')->all();
        $techniques = DB::table('techniques')->orderBy('id')->get()->keyBy('id');
        $implementations = DB::table('implementations')->orderBy('id')->get()->keyBy('id');
        $documentations = DB::table('documentations')->orderBy('id')->get()->keyBy('id');

        $technique = fn ($id) => trim($techniques[$id]->title.' '.$techniques[$id]->version);
        $implementation = fn ($id) => $implementations[$id]->git_repo_id ?? $implementations[$id]->title;
        $documentation = fn ($id) => $documentations[$id]->url ?? $documentations[$id]->title;
        $entity = ['technique' => $technique, 'implementation' => $implementation, 'documentation' => $documentation];

        return [
            'techniques' => $this->sorted($techniques->map(fn ($row) => [
                'title' => $row->title,
                'version' => $row->version,
                'scope' => $scopes[$row->type] ?? null,
                'deleted_at' => $row->deleted_at,
            ])),
            'implementations' => $this->sorted($implementations->map(fn ($row) => [
                'git_repo_id' => $row->git_repo_id === null ? null : (string) $row->git_repo_id,
                'title' => $row->title,
                'scope' => $scopes[$row->type] ?? null,
                'url' => $row->url,
                'maintain_status' => (bool) $row->maintain_status,
            ])),
            'documentations' => $this->sorted($documentations->map(fn ($row) => [
                'title' => $row->title,
                'url' => $row->url,
                'scope' => $scopes[$row->type] ?? null,
                'status' => $row->status === null ? null : (int) $row->status,
            ])),
            'technique_implementation' => $this->sorted(DB::table('technique_implementation')->get()->map(fn ($row) => [
                'technique' => $technique($row->technique_id),
                'implementation' => (string) $implementation($row->implementation_id),
                'relation' => $relations[$row->relation_id],
                'last_seen_at' => $row->last_seen_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])),
            'entity_relations' => $this->sorted(DB::table('entity_relations')->get()->map(fn ($row) => [
                'entity_type' => $row->entity_type,
                'subject' => (string) $entity[$row->entity_type]($row->subject_id),
                'object' => (string) $entity[$row->entity_type]($row->object_id),
                'relation' => $relations[$row->relation_id],
            ])),
            'documentation_technique' => $this->sorted(DB::table('documentation_technique')->get()->map(fn ($row) => [
                'documentation' => $documentation($row->documentation_id),
                'technique' => $technique($row->technique_id),
                'relation' => $relations[$row->relation_id],
            ])),
            'documentation_implementation' => $this->sorted(DB::table('documentation_implementation')->get()->map(fn ($row) => [
                'documentation' => $documentation($row->documentation_id),
                'implementation' => (string) $implementation($row->implementation_id),
                'relation' => $relations[$row->relation_id],
            ])),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sorted($rows): array
    {
        $rows = array_values($rows->all());
        usort($rows, fn ($a, $b) => strcmp(json_encode($a, JSON_UNESCAPED_UNICODE), json_encode($b, JSON_UNESCAPED_UNICODE)));

        return $rows;
    }
}
