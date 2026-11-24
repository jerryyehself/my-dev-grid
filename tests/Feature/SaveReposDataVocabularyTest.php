<?php

namespace Tests\Feature;

use App\Enums\ResLabelType;
use App\Models\EntityRelation;
use App\Models\Relation;
use App\Models\ResLabel;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\SaveReposDataService;
use Database\Seeders\GitHubReposSnapshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 同步認技術時讀詞表：先比正式名、再比別名，沿停用技術的改用對象走，跳過停用而沒有改用對象的。
 *
 * 用 repo 裡的 GitHub 快照（`GitHubReposSnapshotSeeder` 的 fixture）同步兩次，第二次之前改詞表（tailwind 改名加別名、
 * 停用並改用另一筆、只停用），看第二次同步有沒有多建技術、邊連到哪裡。
 */
class SaveReposDataVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(GitHubReposSnapshotSeeder::class);
    }

    private function syncAgain(): void
    {
        $this->seed(GitHubReposSnapshotSeeder::class);
    }

    private function tailwind(): Technique
    {
        return Technique::where('title', 'tailwind')->whereNull('version')->sole();
    }

    private function deprecate(Technique $technique, ?Technique $replacement): void
    {
        DB::table('techniques')->where('id', $technique->id)->update(['deprecated_at' => now(), 'replaced_by_id' => $replacement?->id]);
    }

    /** @return list<int> */
    private function projectIdsLinkedTo(Technique $technique): array
    {
        return DB::table('technique_implementation')->where('technique_id', $technique->id)->orderBy('implementation_id')
            ->pluck('implementation_id')->map(fn ($id) => (int) $id)->all();
    }

    #[TestDox('正式名改成 Tailwind CSS、舊名留成別名後再同步，不會另建一筆 tailwind，邊照常連在原本那筆')]
    public function test_renamed_title_with_alias_does_not_create_a_duplicate(): void
    {
        $tailwind = $this->tailwind();
        $projects = $this->projectIdsLinkedTo($tailwind);
        $this->assertNotSame([], $projects, '快照裡有 tailwind 的 topic 與套件，第一次同步就該連上');
        $count = Technique::count();

        $tailwind->update(['title' => 'Tailwind CSS']);
        ResLabel::create(['res_type' => 'technique', 'res_id' => $tailwind->id, 'label' => 'tailwind', 'label_type' => ResLabelType::Alt, 'locale' => 'zh-Hant', 'note' => '舊正式名']);
        ResLabel::create(['res_type' => 'technique', 'res_id' => $tailwind->id, 'label' => 'tailwindcss', 'label_type' => ResLabelType::Hidden]);
        $this->syncAgain();

        $this->assertSame($count, Technique::count());
        $this->assertSame(0, Technique::whereRaw('lower(title) = ?', ['tailwind'])->count());
        $this->assertSame($projects, $this->projectIdsLinkedTo($tailwind));
    }

    #[TestDox('對照組：只改正式名、沒留別名時，同步會另建一筆 tailwind（所以改名前要先加別名）')]
    public function test_renamed_title_without_alias_creates_a_duplicate(): void
    {
        $tailwind = $this->tailwind();
        $count = Technique::count();

        $tailwind->update(['title' => 'Tailwind CSS']);
        $this->syncAgain();

        $this->assertSame($count + 1, Technique::count());
        $this->assertSame(1, Technique::where('title', 'tailwind')->count());
    }

    #[TestDox('tailwind 停用、改用 Tailwind CSS 之後再同步，不會把 tailwind 建回來，新的邊連到改用對象')]
    public function test_deprecated_title_follows_its_replacement(): void
    {
        $tailwind = $this->tailwind();
        $projects = $this->projectIdsLinkedTo($tailwind);
        $replacement = Technique::create(['type' => $tailwind->type, 'title' => 'Tailwind CSS']);
        $this->deprecate($tailwind, $replacement);
        $count = Technique::count();

        $this->syncAgain();

        $this->assertSame($count, Technique::count());
        $this->assertSame($projects, $this->projectIdsLinkedTo($replacement));
        // 同步不刪也不搬舊的邊
        $this->assertSame($projects, $this->projectIdsLinkedTo($tailwind));
    }

    #[TestDox('只停用：停用而沒有改用對象的技術，同步不建回來、不新增也不更新它的邊')]
    public function test_deprecated_title_without_replacement_is_skipped(): void
    {
        $leetcode = Technique::where('title', 'leetcode')->sole();
        $projects = $this->projectIdsLinkedTo($leetcode);
        $this->assertCount(1, $projects);
        $this->deprecate($leetcode, null);
        // 先拿掉既有的邊，看同步會不會再新增
        DB::table('technique_implementation')->where('technique_id', $leetcode->id)->delete();
        $count = Technique::count();
        Log::spy();

        $this->syncAgain();

        $this->assertSame($count, Technique::count());
        $this->assertSame([], $this->projectIdsLinkedTo($leetcode), '停用的技術不新增邊');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, '「leetcode」對到的技術已停用'));
    }

    #[TestDox('只停用：已經有的邊留著，但同步不再更新它的 last_seen_at')]
    public function test_existing_edges_of_a_skipped_technique_are_not_touched(): void
    {
        $leetcode = Technique::where('title', 'leetcode')->sole();
        $this->deprecate($leetcode, null);
        DB::table('technique_implementation')->where('technique_id', $leetcode->id)->update(['last_seen_at' => '2001-01-01 00:00:00']);

        $this->syncAgain();

        $this->assertSame(['2001-01-01 00:00:00'], DB::table('technique_implementation')->where('technique_id', $leetcode->id)->pluck('last_seen_at')->all());
    }

    #[TestDox('別名掛在兩個技術上（token 這種同形異義）：同步不猜、不另建，記一筆 warning')]
    public function test_homonym_alias_is_skipped(): void
    {
        $concept = Scope::where('name', 'concept')->value('id');
        foreach (['token（驗證）', 'token（LLM）'] as $title) {
            $technique = Technique::create(['type' => $concept, 'title' => $title]);
            ResLabel::create(['res_type' => 'technique', 'res_id' => $technique->id, 'label' => 'token', 'label_type' => ResLabelType::Alt]);
        }
        $count = Technique::count();
        Log::spy();

        (new SaveReposDataService(collect([[
            'id' => 9001, 'git_repo_id' => 9001, 'title' => 'token-demo', 'html_url' => 'https://github.com/acme/token-demo',
            'topics' => ['token'], 'languages' => [],
        ]])))->save_repos_data();

        $this->assertSame($count, Technique::count());
        $project = DB::table('implementations')->where('git_repo_id', 9001)->value('id');
        $this->assertNotNull($project);
        $this->assertSame(0, DB::table('technique_implementation')->where('implementation_id', $project)->count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '「token」是不只一個技術的別名'));
    }

    #[TestDox('版本那一筆停用、改用不分版本的那一筆時，不會連成自己 isVersionOf 自己')]
    public function test_versioned_technique_merged_into_its_base_does_not_link_to_itself(): void
    {
        $vue = Technique::where('title', 'Vue')->whereNull('version')->sole();
        $vue3 = Technique::where('title', 'Vue')->where('version', '3')->sole();
        $this->deprecate($vue3, $vue);

        $this->syncAgain();

        $isVersionOf = Relation::where('name', 'isVersionOf')->value('id');
        $this->assertSame(0, EntityRelation::where('relation_id', $isVersionOf)->whereColumn('subject_id', 'object_id')->count());
        $this->assertSame(0, Technique::where('title', 'Vue')->where('version', '3')->whereNull('deprecated_at')->count());
    }
}
