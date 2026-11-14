<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\Concerns\FakesArticleSourceRepo;
use Tests\TestCase;

/**
 * 匯入文章時，front matter 的 `about_techniques`、`about_projects` 變成文章到技術、專案的連結
 * （`ArticleSourceService` 呼叫 `ArticleAboutLinker`）。
 *
 * 規則：只新增不刪除、重複匯入不重複、後台加的連結不動、對不上的名稱只警告不自動建立。
 * 技術與專案走不同的連結表與述詞，需要兩邊都測的行為用 `kinds()` 各跑一遍。
 * 用 `$this->seed()` 種出真正的分類與述詞：連結的分類檢查要靠 `post` 在 `Documentation` 底下才會過。
 */
class ArticleSourceAboutRelationsTest extends TestCase
{
    use CapturesSecurityEvents;
    use FakesArticleSourceRepo;
    use RefreshDatabase;

    /** 種類 => [front matter 欄位, 連結表, 連結表裡實體的欄位, 述詞, 實體掛的分類, 另一種的述詞] */
    private const KINDS = [
        'technique' => ['about_techniques', 'documentation_technique', 'technique_id', 'about', 'framework', 'aboutProject'],
        'project' => ['about_projects', 'documentation_implementation', 'implementation_id', 'aboutProject', 'project', 'about'],
    ];

    private Scope $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->post = Scope::where('name', 'post')->firstOrFail();
        $this->configureArticleSource();
        $this->fakeGitHub();
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield '技術' => ['technique'];
        yield '專案' => ['project'];
    }

    #[DataProvider('kinds')]
    #[TestDox('新增文章：front matter 列出的名稱連到站上的技術或專案，名稱不分大小寫')]
    public function test_create_links_the_listed_names_ignoring_case(string $kind): void
    {
        [$field, $table, $column, $predicate] = self::KINDS[$kind];
        $first = $this->target($kind, 'Alpha');
        $second = $this->target($kind, 'Beta Gamma');
        $untouched = $this->target($kind, 'Delta');
        $content = self::article('post-a', '文章', extra: [$field => '[alpha, "BETA gamma"]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $response = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk();

        $this->assertSame([], $response->json('data.0.warnings'));
        $id = $response->json('data.0.documentation_id');
        $this->assertSame(
            [$first->id => Relation::where('name', $predicate)->value('id'), $second->id => Relation::where('name', $predicate)->value('id')],
            $this->links($table, $column, $id),
        );
        $this->assertNotContains($untouched->id, array_keys($this->links($table, $column, $id)));
    }

    #[TestDox('技術有版本時，用「名稱 版本」對上那一筆；只寫名稱對上的是沒有版本的那一筆')]
    public function test_technique_names_match_the_label_with_version(): void
    {
        $plain = $this->target('technique', 'Vue');
        $v3 = $this->target('technique', 'Vue', version: '3');
        $content = self::article('post-a', '文章', extra: ['about_techniques' => '["vue 3", VUE]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $id = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk()->json('data.0.documentation_id');

        $this->assertEqualsCanonicalizing([$plain->id, $v3->id], array_keys($this->links('documentation_technique', 'technique_id', $id)));
    }

    #[DataProvider('kinds')]
    #[TestDox('找不到的名稱只警告，不自動建立；其他名稱照常連結，匯入仍成功')]
    public function test_unknown_names_warn_and_are_never_created(string $kind): void
    {
        [$field, $table, $column] = self::KINDS[$kind];
        $known = $this->target($kind, 'Known');
        $content = self::article('post-a', '文章', extra: [$field => '[Known, Missing]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();
        $count = $this->modelCount($kind);

        $response = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk();

        $response->assertJsonCount(1, 'data.0.warnings')
            ->assertJsonPath('data.0.warnings.0.code', 'about_not_found')
            ->assertJsonPath('data.0.warnings.0.line', null);
        $this->assertStringContainsString('Missing', $response->json('data.0.warnings.0.message'));
        $this->assertSame($count, $this->modelCount($kind));
        $this->assertSame([$known->id], array_keys($this->links($table, $column, $response->json('data.0.documentation_id'))));
    }

    #[DataProvider('kinds')]
    #[TestDox('名稱對上不只一筆時警告，不猜是哪一筆')]
    public function test_ambiguous_names_warn_and_link_nothing(string $kind): void
    {
        [$field, $table, $column] = self::KINDS[$kind];
        $this->target($kind, 'Twin');
        $this->target($kind, 'twin');
        $content = self::article('post-a', '文章', extra: [$field => '[Twin]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $response = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk();

        $response->assertJsonPath('data.0.warnings.0.code', 'about_ambiguous');
        $this->assertSame([], $this->links($table, $column, $response->json('data.0.documentation_id')));
    }

    #[DataProvider('kinds')]
    #[TestDox('已軟刪除的技術或專案不算數：當作找不到')]
    public function test_soft_deleted_targets_are_not_found(string $kind): void
    {
        [$field, $table, $column] = self::KINDS[$kind];
        $this->target($kind, 'Gone')->delete();
        $content = self::article('post-a', '文章', extra: [$field => '[Gone]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $response = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk();

        $response->assertJsonPath('data.0.warnings.0.code', 'about_not_found');
        $this->assertSame([], $this->links($table, $column, $response->json('data.0.documentation_id')));
    }

    #[DataProvider('kinds')]
    #[TestDox('重複匯入（更新）不會產生重複的連結')]
    public function test_reimport_is_idempotent(string $kind): void
    {
        [$field, $table, $column, $predicate] = self::KINDS[$kind];
        $target = $this->target($kind, 'Alpha');
        $v1 = self::article('post-a', '文章', "第一版。\n", [$field => '[Alpha]']);
        $commit1 = $this->publishSourceFiles(['a.md' => $v1]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('post-a', $v1, 'create')], $commit1)->assertOk()->json('data.0.documentation_id');
        $before = DB::table($table)->where('documentation_id', $id)->get()->all();
        $this->assertCount(1, $before);

        foreach (['第二版。', '第三版。'] as $body) {
            $next = self::article('post-a', '文章', "{$body}\n", [$field => '[Alpha]']);
            $commit = $this->publishSourceFiles(['a.md' => $next]);
            $this->import([$this->item('post-a', $next, 'update')], $commit)->assertOk()->assertJsonPath('data.0.warnings', []);
        }

        $this->assertEquals($before, DB::table($table)->where('documentation_id', $id)->get()->all());
        $this->assertSame([$target->id => Relation::where('name', $predicate)->value('id')], $this->links($table, $column, $id));
    }

    #[DataProvider('kinds')]
    #[TestDox('只新增不刪除：front matter 拿掉名稱，原本的連結（匯入的或後台加的）都還在')]
    public function test_import_never_removes_links(string $kind): void
    {
        [$field, $table, $column, $predicate] = self::KINDS[$kind];
        $imported = $this->target($kind, 'Imported');
        $byEditor = $this->target($kind, 'ByEditor');
        $late = $this->target($kind, 'Late');
        $v1 = self::article('post-a', '文章', "第一版。\n", [$field => '[Imported]']);
        $commit1 = $this->publishSourceFiles(['a.md' => $v1]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('post-a', $v1, 'create')], $commit1)->assertOk()->json('data.0.documentation_id');
        // 後台加的連結：用另一個述詞（技術用 specs、專案用 documents）
        $editorPredicate = Relation::where('name', $kind === 'technique' ? 'specs' : 'documents')->value('id');
        $this->relationOf(Documentation::findOrFail($id), $kind)->attach($byEditor->id, ['relation_id' => $editorPredicate]);

        foreach ([[$field => '[]'], [], [$field => '[Imported, Late]']] as $extra) {
            $next = self::article('post-a', '文章', '第'.count($extra).'版。'."\n", $extra);
            $commit = $this->publishSourceFiles(['a.md' => $next]);
            $this->import([$this->item('post-a', $next, 'update')], $commit)->assertOk();
        }

        $this->assertSame(
            [
                $imported->id => Relation::where('name', $predicate)->value('id'),
                $byEditor->id => $editorPredicate,
                // 新增的名稱連上時，原本的連結也還在（attach，不是 sync）
                $late->id => Relation::where('name', $predicate)->value('id'),
            ],
            $this->links($table, $column, $id),
        );
    }

    #[DataProvider('kinds')]
    #[TestDox('文章已經用別的述詞連到它（後台加的）就不再加第二條，後台存檔仍然可以')]
    public function test_an_existing_link_with_another_predicate_is_left_alone(string $kind): void
    {
        [$field, $table, $column] = self::KINDS[$kind];
        $target = $this->target($kind, 'Alpha');
        $v1 = self::article('post-a', '文章', "第一版。\n");
        $commit1 = $this->publishSourceFiles(['a.md' => $v1]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('post-a', $v1, 'create')], $commit1)->assertOk()->json('data.0.documentation_id');
        $editorPredicate = Relation::where('name', $kind === 'technique' ? 'specs' : 'documents')->value('id');
        $this->relationOf(Documentation::findOrFail($id), $kind)->attach($target->id, ['relation_id' => $editorPredicate]);
        $v2 = self::article('post-a', '文章', "第二版。\n", [$field => '[Alpha]']);
        $commit2 = $this->publishSourceFiles(['a.md' => $v2]);

        $this->import([$this->item('post-a', $v2, 'update')], $commit2)->assertOk()->assertJsonPath('data.0.warnings', []);

        $this->assertSame([$target->id => $editorPredicate], $this->links($table, $column, $id));
        // 後台編輯器一次送一整份清單；每個技術只能有一條連結，否則這裡會撞到連結表的唯一約束
        $this->putJson("/api/documentations/{$id}", [
            'type' => $this->post->id,
            'title' => '文章',
            self::payloadKey($kind) => [['id' => $target->id, 'relation_id' => $editorPredicate]],
        ])->assertOk();
    }

    #[TestDox('匯入的連結，後台編輯器把整份清單送回來存檔仍然通過')]
    public function test_editor_can_save_the_article_after_an_import(): void
    {
        $technique = $this->target('technique', 'Alpha');
        $project = $this->target('project', 'Beta');
        $content = self::article('post-a', '文章', extra: ['about_techniques' => '[Alpha]', 'about_projects' => '[Beta]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();
        $id = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk()->json('data.0.documentation_id');

        $this->putJson("/api/documentations/{$id}", [
            'type' => $this->post->id,
            'title' => '文章',
            'techniques' => [['id' => $technique->id, 'relation_id' => Relation::where('name', 'about')->value('id')]],
            'implementations' => [['id' => $project->id, 'relation_id' => Relation::where('name', 'aboutProject')->value('id')]],
        ])->assertOk();

        $this->assertCount(1, $this->links('documentation_technique', 'technique_id', $id));
        $this->assertCount(1, $this->links('documentation_implementation', 'implementation_id', $id));
    }

    #[DataProvider('kinds')]
    #[TestDox('技術、專案的分類不在述詞規定的分類底下時警告，不建立連結')]
    public function test_target_outside_the_predicate_scope_is_not_linked(string $kind): void
    {
        [$field, $table, $column] = self::KINDS[$kind];
        $this->target($kind, 'Misfiled', Scope::where('name', 'document')->value('id'));
        $content = self::article('post-a', '文章', extra: [$field => '[Misfiled]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $response = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk();

        $response->assertJsonPath('data.0.warnings.0.code', 'about_scope_mismatch');
        $this->assertSame([], $this->links($table, $column, $response->json('data.0.documentation_id')));
    }

    #[TestDox('文章自己的分類不在 Documentation 底下（後台改過）時警告，不建立連結')]
    public function test_article_outside_the_documentation_scope_is_not_linked(): void
    {
        $this->target('technique', 'Alpha');
        $v2 = self::article('post-a', '文章', "第二版。\n", ['about_techniques' => '[Alpha]']);
        $commit = $this->publishSourceFiles(['a.md' => $v2]);
        $documentation = Documentation::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'body' => "第一版。\n"]);
        $documentation->forceFill([
            'source_repo' => $this->sourceRepo, 'source_key' => 'post-a', 'source_path' => 'a.md',
            'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => hash('sha256', "第一版。\n"),
        ])->save();
        $this->actingAsOwner();

        $this->import([$this->item('post-a', $v2, 'update')], $commit)
            ->assertOk()
            ->assertJsonPath('data.0.warnings.0.code', 'about_scope_mismatch');

        $this->assertSame([], $this->links('documentation_technique', 'technique_id', $documentation->id));
    }

    #[DataProvider('kinds')]
    #[TestDox('述詞不存在（或已被刪除）時警告，不建立任何連結，匯入本身仍成功')]
    public function test_missing_predicate_warns_and_still_imports(string $kind): void
    {
        [$field, $table, $column, $predicate] = self::KINDS[$kind];
        $this->target($kind, 'Alpha');
        Relation::where('name', $predicate)->get()->each->delete();
        $content = self::article('post-a', '文章', extra: [$field => '[Alpha]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $response = $this->import([$this->item('post-a', $content, 'create')], $commit)->assertOk();

        $response->assertJsonPath('data.0.warnings.0.code', 'about_predicate_missing');
        $this->assertSame([], $this->links($table, $column, $response->json('data.0.documentation_id')));
        $this->assertSame('文章', Documentation::findOrFail($response->json('data.0.documentation_id'))->title);
    }

    #[TestDox('連結（link）不讀內容，所以不建立 about 連結')]
    public function test_link_action_creates_no_about_links(): void
    {
        $this->target('technique', 'Alpha');
        $content = self::article('post-a', '文章', extra: ['about_techniques' => '[Alpha]']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'body' => "站上的版本。\n"]);
        $this->actingAsOwner();

        $this->import([$this->item('post-a', $content, 'link', ['documentation_id' => $documentation->id])], $commit)
            ->assertOk()
            ->assertJsonPath('data.0.warnings', []);

        $this->assertSame(0, DB::table('documentation_technique')->count());
    }

    #[TestDox('同一批裡每篇文章各連各的，其中一篇的警告不影響另一篇')]
    public function test_each_article_in_a_batch_gets_its_own_links_and_warnings(): void
    {
        $alpha = $this->target('technique', 'Alpha');
        $beta = $this->target('project', 'Beta');
        $a = self::article('post-a', '甲', extra: ['about_techniques' => '[Alpha, Nope]']);
        $b = self::article('post-b', '乙', extra: ['about_projects' => '[Beta]']);
        $c = self::article('post-c', '丙');
        $commit = $this->publishSourceFiles(['a.md' => $a, 'b.md' => $b, 'c.md' => $c]);
        $this->actingAsOwner();

        $response = $this->import([
            $this->item('post-a', $a, 'create'),
            $this->item('post-b', $b, 'create'),
            $this->item('post-c', $c, 'create'),
        ], $commit)->assertOk();

        $this->assertSame(['about_not_found'], array_column($response->json('data.0.warnings'), 'code'));
        $this->assertSame([], $response->json('data.1.warnings'));
        $this->assertSame([], $response->json('data.2.warnings'));
        $this->assertSame([$alpha->id], array_keys($this->links('documentation_technique', 'technique_id', $response->json('data.0.documentation_id'))));
        $this->assertSame([$beta->id], array_keys($this->links('documentation_implementation', 'implementation_id', $response->json('data.1.documentation_id'))));
        $this->assertSame([], $this->links('documentation_technique', 'technique_id', $response->json('data.2.documentation_id')));
    }

    #[TestDox('寫入連結時記一筆 sync 稽核紀錄，只含連到哪些 id，沒有新連結就不記')]
    public function test_attached_links_are_audited_like_the_editor(): void
    {
        $alpha = $this->target('technique', 'Alpha');
        $v1 = self::article('post-a', '文章', "第一版。\n", ['about_techniques' => '[Alpha]']);
        $commit1 = $this->publishSourceFiles(['a.md' => $v1]);
        $user = $this->actingAsOwner();
        $this->captureLogs();

        $id = $this->import([$this->item('post-a', $v1, 'create')], $commit1)->assertOk()->json('data.0.documentation_id');

        $syncs = $this->syncEvents();
        $this->assertCount(1, $syncs);
        $this->assertSame([
            'event' => 'authz_admin',
            'user_id' => $user->id,
            'action' => 'sync',
            'object' => 'documentation',
            'object_id' => $id,
            'relation' => 'techniques',
            'attached' => [$alpha->id],
        ], $syncs[0]->context);

        // 第二次匯入同樣的名稱，沒有新連結，就沒有新的 sync 紀錄
        $this->captureLogs();
        $v2 = self::article('post-a', '文章', "第二版。\n", ['about_techniques' => '[Alpha]']);
        $commit2 = $this->publishSourceFiles(['a.md' => $v2]);
        $this->import([$this->item('post-a', $v2, 'update')], $commit2)->assertOk();
        $this->assertCount(0, $this->syncEvents());
    }

    #[TestDox('寫入中途失敗整批回滾時，前一項已經寫下的連結也不會留下')]
    public function test_links_are_rolled_back_with_the_batch(): void
    {
        $this->target('technique', 'Alpha');
        $updated = self::article('post-a', '甲新版', "新版。\n", ['about_techniques' => '[Alpha]']);
        $created = self::article('post-b', '乙');
        $commit = $this->publishSourceFiles(['a.md' => $updated, 'b.md' => $created]);
        $documentation = Documentation::factory()->create(['type' => $this->post->id, 'title' => '甲', 'body' => "舊版。\n"]);
        $documentation->forceFill([
            'source_repo' => $this->sourceRepo, 'source_key' => 'post-a', 'source_path' => 'a.md',
            'source_blob_sha' => str_repeat('1', 40), 'source_body_hash' => hash('sha256', "舊版。\n"),
        ])->save();
        $this->actingAsOwner();
        // 所有事前檢查都會過；第一項 update 把連結寫完之後，第二項 create 才查 post 分類，查不到整批失敗
        $this->post->delete();

        $this->import([$this->item('post-a', $updated, 'update'), $this->item('post-b', $created, 'create')], $commit)
            ->assertStatus(503)
            ->assertJsonPath('reason', 'post_scope_missing');

        $this->assertSame("舊版。\n", $documentation->refresh()->body);
        $this->assertSame(0, DB::table('documentation_technique')->count());
    }

    #[TestDox('about 格式不對的檔案列成 invalid 並說明原因，不能匯入')]
    public function test_malformed_about_is_listed_as_invalid_and_cannot_be_imported(): void
    {
        $content = self::article('post-a', '文章', extra: ['about_projects' => 'my-app']);
        $commit = $this->publishSourceFiles(['a.md' => $content]);
        $this->actingAsOwner();

        $this->getJson('/api/article-sources')->assertOk()
            ->assertJsonPath('items.0.status', 'invalid')
            ->assertJsonPath('items.0.reason', 'about_projects_invalid');
        $this->import([$this->item('post-a', $content, 'create')], $commit)->assertConflict();
        $this->assertSame(0, Documentation::count());
    }

    private function target(string $kind, string $title, ?int $type = null, ?string $version = null): Model
    {
        $type ??= Scope::where('name', self::KINDS[$kind][4])->value('id');

        return $kind === 'technique'
            ? Technique::factory()->create(['type' => $type, 'title' => $title, 'version' => $version])
            : Implementation::factory()->create(['type' => $type, 'title' => $title]);
    }

    private function modelCount(string $kind): int
    {
        return $kind === 'technique' ? Technique::withTrashed()->count() : Implementation::withTrashed()->count();
    }

    private function relationOf(Documentation $documentation, string $kind)
    {
        return $kind === 'technique' ? $documentation->techniques() : $documentation->implementations();
    }

    private static function payloadKey(string $kind): string
    {
        return $kind === 'technique' ? 'techniques' : 'implementations';
    }

    /** @return array<int, int> 實體 id => 述詞 id */
    private function links(string $table, string $column, int $documentationId): array
    {
        return DB::table($table)->where('documentation_id', $documentationId)->pluck('relation_id', $column)->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<MessageLogged> */
    private function syncEvents(): array
    {
        return array_values(array_filter(
            $this->securityEvents('authz_admin'),
            fn (MessageLogged $message) => $message->context['action'] === 'sync',
        ));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(string $key, string $content, string $action, array $extra = []): array
    {
        return ['key' => $key, 'expected_blob_sha' => self::blobSha($content), 'action' => $action] + $extra;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function import(array $items, string $commit): TestResponse
    {
        return $this->postJson('/api/article-sources/import', ['commit_sha' => $commit, 'items' => $items]);
    }
}
