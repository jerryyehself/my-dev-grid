<?php

namespace Tests\Feature;

use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Service\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 同步時的權威控制與技術版本（2026-09-30 使用者同意）：
 * - 同一個技術的不同寫法（PHP／php、Vue／vue／vuejs）只建一筆
 * - 版本黏在名稱上的 topic（vue3）變成「Vue 版本 3」，用 isVersionOf 連回 Vue
 * - 專案可以同時連到同一個技術的好幾個版本，升級的歷史不會被蓋掉
 * - 每次同步到就更新 last_seen_at
 */
class TechniqueAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function sync(array $topics, array $languages = [], int $id = 111): void
    {
        (new SaveReposDataService(collect([[
            'id' => $id,
            'git_repo_id' => $id,
            'title' => "demo-{$id}",
            'html_url' => "https://github.com/acme/demo-{$id}",
            'description' => null,
            'topics' => $topics,
            'languages' => $languages,
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'acme',
        ]])))->save_repos_data();
    }

    private function usedBy(): int
    {
        return Relation::where('name', 'usedBy')->value('id');
    }

    /** @return array<int, string> 這個專案連到的技術，「標題 版本」 */
    private function projectTechniques(int $gitRepoId = 111): array
    {
        return Implementation::where('git_repo_id', $gitRepoId)->firstOrFail()
            ->techniques()->get()
            ->map(fn ($t) => trim("{$t->title} {$t->version}"))
            ->sort()->values()->all();
    }

    public function test_language_and_topic_spellings_of_the_same_technique_become_one_record(): void
    {
        $this->sync(['php', 'vuejs'], ['PHP', 'Vue']);

        $this->assertSame(1, Technique::whereRaw('lower(title) = ?', ['php'])->count());
        $this->assertSame(1, Technique::whereRaw('lower(title) = ?', ['vue'])->whereNull('version')->count());
        $this->assertSame(['PHP', 'Vue'], $this->projectTechniques());

        // 標準類別以權威表為準：Vue 是框架，不是 languages API 說的語言
        $this->assertSame('framework', Technique::where('title', 'Vue')->first()->scope->name);
        $this->assertSame('language', Technique::where('title', 'PHP')->first()->scope->name);
    }

    public function test_unlisted_names_are_matched_case_insensitively(): void
    {
        $this->sync([], ['TypeScript']);
        $this->sync(['typescript'], [], 222);

        $this->assertSame(1, Technique::whereRaw('lower(title) = ?', ['typescript'])->count());
        $this->assertSame(['TypeScript'], $this->projectTechniques(222));
    }

    public function test_versioned_topic_becomes_a_version_record_linked_to_its_base(): void
    {
        $this->sync(['vue3'], ['Vue']);

        $base = Technique::where('title', 'Vue')->whereNull('version')->firstOrFail();
        $v3 = Technique::where('title', 'Vue')->where('version', '3')->firstOrFail();

        $this->assertTrue(EntityRelation::where([
            'entity_type' => 'technique',
            'subject_id' => $v3->id,
            'object_id' => $base->id,
            'relation_id' => Relation::where('name', 'isVersionOf')->value('id'),
        ])->exists());

        // 用的是哪一版已經知道了，專案只連 Vue 3，不再另外連版本留空的 Vue
        $this->assertSame(['Vue 3'], $this->projectTechniques());

        // requires JavaScript 掛在版本留空的那一筆上，版本靠 isVersionOf 推得到
        $javascript = Technique::where('title', 'JavaScript')->firstOrFail();
        $this->assertTrue(EntityRelation::where(['subject_id' => $base->id, 'object_id' => $javascript->id])->exists());
        $this->assertFalse(EntityRelation::where(['subject_id' => $v3->id, 'object_id' => $javascript->id])->exists());
    }

    public function test_an_existing_version_less_edge_is_still_refreshed_when_the_version_is_known(): void
    {
        // 合併前就有的「專案 uses Vue」：同步現在只會新建 Vue 3 的邊，但舊的 Vue 邊這次也看到了，
        // last_seen_at 要跟著更新，不然會被當成「以前用過」
        $this->travelTo('2026-09-01 00:00:00');
        $this->sync([], ['Vue']);
        $this->travelTo('2026-09-30 00:00:00');
        $this->sync(['vue3'], ['Vue']);

        $this->assertSame(['Vue', 'Vue 3'], $this->projectTechniques());
        $seen = DB::table('technique_implementation')->pluck('last_seen_at')->map(fn ($v) => substr((string) $v, 0, 10))->unique()->values()->all();
        $this->assertSame(['2026-09-30'], $seen);
    }

    public function test_package_names_ending_in_digits_are_not_split(): void
    {
        $this->sync(['isbn3', 'html5-qrcode']);

        $this->assertSame(['html5-qrcode', 'isbn3'], $this->projectTechniques());
        $this->assertSame(0, Technique::whereNotNull('version')->count());
    }

    public function test_upgrading_keeps_the_old_version_and_only_refreshes_the_new_one(): void
    {
        // 專案以前用 Vue 2（假設是之前手動或舊資料建的），同步那天只看到 vue3
        $framework = Scope::where('name', 'framework')->value('id');
        $v2 = Technique::create(['type' => $framework, 'title' => 'Vue', 'version' => '2']);
        $this->sync([]);
        $project = Implementation::where('git_repo_id', 111)->firstOrFail();
        DB::table('technique_implementation')->insert([
            'implementation_id' => $project->id,
            'technique_id' => $v2->id,
            'relation_id' => $this->usedBy(),
            'last_seen_at' => '2025-01-01 00:00:00',
            'created_at' => '2024-06-01 00:00:00',
            'updated_at' => '2024-06-01 00:00:00',
        ]);

        $this->travelTo('2026-09-30 12:00:00');
        $this->sync(['vue3']);

        $this->assertSame(['Vue 2', 'Vue 3'], $this->projectTechniques());

        $lastSeen = DB::table('technique_implementation')
            ->join('techniques', 'techniques.id', '=', 'technique_implementation.technique_id')
            ->where('implementation_id', $project->id)
            ->pluck('technique_implementation.last_seen_at', 'techniques.version')
            ->map(fn ($v) => substr((string) $v, 0, 10));

        $this->assertSame('2025-01-01', $lastSeen['2'], '沒再看到的舊版本不動，這樣才分得出是以前用的');
        $this->assertSame('2026-09-30', $lastSeen['3']);
    }

    public function test_resync_refreshes_last_seen_at_without_duplicating_the_edge(): void
    {
        $this->travelTo('2026-09-01 00:00:00');
        $this->sync(['laravel']);
        $this->travelTo('2026-09-30 00:00:00');
        $this->sync(['laravel']);

        $rows = DB::table('technique_implementation')->get();
        $laravel = Technique::where('title', 'laravel')->value('id');
        $row = $rows->firstWhere('technique_id', $laravel);

        $this->assertSame(1, $rows->where('technique_id', $laravel)->count());
        $this->assertStringStartsWith('2026-09-30', (string) $row->last_seen_at);
        $this->assertStringStartsWith('2026-09-01', (string) $row->created_at);
    }

    public function test_sync_does_not_rewrite_other_predicates_on_the_same_pair(): void
    {
        // 手動建的「Claude assists 這個專案」，同步再看到 topic claude 時不能被改成 usedBy
        $this->sync([]);
        $project = Implementation::where('git_repo_id', 111)->firstOrFail();
        $claude = Technique::create(['type' => Scope::where('name', 'packagetool')->value('id'), 'title' => 'claude']);
        $assists = Relation::where('name', 'assists')->value('id');
        DB::table('technique_implementation')->insert([
            'implementation_id' => $project->id,
            'technique_id' => $claude->id,
            'relation_id' => $assists,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sync(['claude']);

        $relations = DB::table('technique_implementation')
            ->where(['implementation_id' => $project->id, 'technique_id' => $claude->id])
            ->pluck('relation_id')->sort()->values()->all();
        $this->assertSame(collect([$assists, $this->usedBy()])->sort()->values()->all(), $relations);
    }
}
