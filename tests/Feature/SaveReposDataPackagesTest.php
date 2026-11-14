<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 同步時從套件清單推「專案 uses 技術」的邊：只有 config/package_technologies.php 對照表裡的套件
 * 會成為邊，topic 仍照原來的方式補上。
 */
class SaveReposDataPackagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @param  array<string, list<string>>  $packages */
    private function sync(array $packages, array $topics = [], array $languages = [], int $id = 111): void
    {
        (new SaveReposDataService(collect([[
            'id' => $id,
            'git_repo_id' => $id,
            'title' => "demo-{$id}",
            'html_url' => "https://github.com/acme/demo-{$id}",
            'description' => null,
            'topics' => $topics,
            'languages' => $languages,
            'packages' => $packages,
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'acme',
        ]])))->save_repos_data();
    }

    /** @return list<string> 專案連到的技術，「標題 版本」 */
    private function projectTechniques(int $id = 111): array
    {
        return Implementation::where('git_repo_id', $id)->firstOrFail()
            ->techniques()->get()
            ->map(fn ($t) => trim("{$t->title} {$t->version}"))
            ->sort()->values()->all();
    }

    #[TestDox('對照表裡的套件變成專案的技術邊，沒列的套件不會')]
    public function test_mapped_packages_become_edges_and_unmapped_do_not(): void
    {
        $this->sync(['npm' => ['vue', 'pinia', 'eslint', 'prettier', 'vitest', 'lodash'], 'composer' => ['laravel/framework', 'phpunit/phpunit']]);

        $this->assertSame(['Laravel', 'Pinia', 'Vue'], $this->projectTechniques());
        $this->assertDatabaseMissing('techniques', ['title' => 'eslint']);
        $this->assertDatabaseMissing('techniques', ['title' => 'phpunit/phpunit']);
        $this->assertDatabaseMissing('techniques', ['title' => 'lodash']);
    }

    #[TestDox('三種生態系的套件都會成為邊')]
    public function test_every_ecosystem_produces_edges(): void
    {
        $this->sync(['npm' => ['typescript'], 'composer' => ['laravel/sanctum'], 'pip' => ['django', 'rdflib']]);

        $this->assertSame(['Django', 'Sanctum', 'TypeScript', 'rdflib'], $this->projectTechniques());
    }

    #[TestDox('套件上圖的技術依對照表歸到 language、framework、packagetool')]
    public function test_package_techniques_get_the_scope_from_the_map(): void
    {
        $this->sync(['npm' => ['typescript', 'vue', 'pinia']]);

        $scopeOf = fn (string $title) => Technique::where('title', $title)->firstOrFail()->scope->name;

        $this->assertSame('language', $scopeOf('TypeScript'));
        $this->assertSame('framework', $scopeOf('Vue'));
        $this->assertSame('packagetool', $scopeOf('Pinia'));
    }

    #[TestDox('topic 仍照原來的方式補上邊，跟套件來的邊並存')]
    public function test_topics_are_still_applied_alongside_packages(): void
    {
        $this->sync(['npm' => ['pinia']], topics: ['laravel', 'leetcode'], languages: ['PHP']);

        $this->assertSame(['PHP', 'Pinia', 'laravel', 'leetcode'], $this->projectTechniques());
    }

    #[TestDox('沒有 packages 欄位（舊快照）或 packages 為空時，行為跟以前一樣')]
    public function test_repos_without_packages_behave_as_before(): void
    {
        (new SaveReposDataService(collect([[
            'id' => 222, 'git_repo_id' => 222, 'title' => 'old', 'html_url' => 'x',
            'topics' => ['laravel'], 'languages' => ['PHP'],
        ]])))->save_repos_data();
        $this->sync([], topics: ['pinia'], id: 333);

        $this->assertSame(['PHP', 'laravel'], $this->projectTechniques(222));
        $this->assertSame(['pinia'], $this->projectTechniques(333));
    }

    #[TestDox('套件跟 topic 指到同一個技術時只有一筆邊')]
    public function test_package_and_topic_for_the_same_technique_make_one_edge(): void
    {
        $this->sync(['npm' => ['pinia', 'tailwindcss', '@tailwindcss/vite']], topics: ['pinia', 'tailwind']);

        $this->assertSame(['pinia', 'tailwind'], $this->projectTechniques());
        $this->assertSame(1, Technique::whereRaw('lower(title) = ?', ['pinia'])->count());
        $this->assertSame(1, Technique::whereRaw('lower(title) = ?', ['tailwind'])->count());
    }

    #[TestDox('套件來的 Vue 跟 topic 的 vue3 並存時，只建 Vue 3 的邊')]
    public function test_package_vue_does_not_add_a_second_edge_next_to_a_vue3_topic(): void
    {
        $this->sync(['npm' => ['vue']], topics: ['vue3']);

        $this->assertSame(['Vue 3'], $this->projectTechniques());
    }

    #[TestDox('套件來的框架一樣連到基礎語言、官方文件')]
    public function test_package_frameworks_get_base_language_and_docs(): void
    {
        $this->sync(['composer' => ['laravel/framework']]);

        $laravel = Technique::where('title', 'Laravel')->firstOrFail();
        $this->assertDatabaseHas('entity_relations', [
            'entity_type' => 'technique',
            'subject_id' => $laravel->id,
            'object_id' => Technique::where('title', 'PHP')->value('id'),
            'relation_id' => Relation::where('name', 'requires')->value('id'),
        ]);
        $this->assertDatabaseHas('documentation_technique', ['technique_id' => $laravel->id]);
    }

    #[TestDox('重複同步不會多出邊，並且更新 last_seen_at')]
    public function test_resync_is_idempotent_and_refreshes_last_seen_at(): void
    {
        $this->sync(['npm' => ['vue']]);
        $this->travel(2)->days();
        $this->sync(['npm' => ['vue']]);

        $rows = DB::table('technique_implementation')->get();

        $this->assertCount(1, $rows);
        $this->assertTrue(now()->diffInSeconds($rows->first()->last_seen_at, true) < 5);
    }

    #[TestDox('套件移除後既有的邊不會被刪，也不會更新 last_seen_at')]
    public function test_a_dropped_package_keeps_its_old_edge_without_refreshing_it(): void
    {
        $this->sync(['npm' => ['vue', 'pinia']]);
        $before = DB::table('technique_implementation')->orderBy('technique_id')->pluck('last_seen_at', 'technique_id');

        $this->travel(2)->days();
        $this->sync(['npm' => ['vue']]);

        $pinia = Technique::where('title', 'Pinia')->value('id');
        $after = DB::table('technique_implementation')->pluck('last_seen_at', 'technique_id');

        $this->assertCount(2, $after);
        $this->assertSame($before[$pinia], $after[$pinia]);
    }
}
