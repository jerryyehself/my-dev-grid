<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RelationSeeder;
use Database\Seeders\ScopeSeeder;
use Database\Seeders\TechniqueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * ReferenceDataSeeder 在每次部署的 migrate 之後自動執行（deploy-cloud-run.yml「Seed reference data」），
 * 正式環境的資料庫裡早就有資料、也有後台改過的內容。這裡用部署時同一條指令跑，確認：
 * 重跑不重複、不蓋掉後台的修改、不把後台刪掉的建回來，而新加的預設資料會補進舊資料庫。
 */
class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /** 部署時 Cloud Run Job 執行的指令：php artisan db:seed --class=ReferenceDataSeeder --force */
    private function deploySeed(): void
    {
        $this->artisan('db:seed', ['--class' => 'ReferenceDataSeeder', '--force' => true])
            ->assertSuccessful();
    }

    #[TestDox('部署時只跑分類、述詞與預設技術節點三支 seeder')]
    public function test_only_reference_data_seeders_run_on_deploy()
    {
        // 加進來的 seeder 每次部署都會在正式環境跑，要先確認符合 ReferenceDataSeeder 的條件
        $this->assertSame([ScopeSeeder::class, RelationSeeder::class, TechniqueSeeder::class], ReferenceDataSeeder::SEEDERS);
    }

    #[TestDox('空資料庫跑部署 seed 只建出預設資料')]
    public function test_deploy_seed_on_an_empty_database_creates_the_reference_data_only()
    {
        Http::preventStrayRequests();

        $this->deploySeed();

        $this->assertSame(17, Scope::count());
        $this->assertSame(23, Relation::count());
        // 不建帳號、不碰內容資料；唯一的技術節點是預設的 Claude Code
        $this->assertSame(0, User::count());
        $this->assertSame(0, Documentation::count());
        $this->assertSame(1, Technique::count());
        $this->assertSame(0, Implementation::count());
        $this->assertSame(0, EntityRelation::count());
    }

    #[TestDox('部署 seed 跑兩次不會多出資料')]
    public function test_running_the_deploy_seed_twice_creates_no_duplicates()
    {
        $this->deploySeed();
        $this->deploySeed();

        $this->assertSame(17, Scope::count());
        $this->assertSame(23, Relation::count());
        $this->assertSame(1, Technique::count());
        $this->assertSame(17, Scope::withTrashed()->distinct()->count('name'));
        $this->assertSame(23, Relation::withTrashed()->distinct()->count('name'));
    }

    #[TestDox('重跑部署 seed 不蓋掉後台改過的內容')]
    public function test_deploy_seed_keeps_edits_made_in_the_admin()
    {
        $this->deploySeed();
        Scope::where('name', 'post')->first()->update(['comment' => '後台改過的說明']);
        Relation::where('name', 'uses')->first()->update(['note' => '後台改過的述詞說明']);

        $this->deploySeed();

        $this->assertSame('後台改過的說明', Scope::where('name', 'post')->value('comment'));
        $this->assertSame('後台改過的述詞說明', Relation::where('name', 'uses')->value('note'));
    }

    #[TestDox('重跑部署 seed 不把後台取消的反向關係配回去')]
    public function test_deploy_seed_does_not_restore_reverse_pairs_removed_in_the_admin()
    {
        $this->deploySeed();
        // 後台編輯畫面取消「有反向關係」＝ reverse_id 設回 null，syncReverse() 會把另一邊也清掉。
        // specs 原本由一段批次更新配對，requires 由 pairReverse() 配對，兩種都要守住。
        Relation::where('name', 'specs')->first()->update(['reverse_id' => null]);
        Relation::where('name', 'requires')->first()->update(['reverse_id' => null]);

        $this->deploySeed();

        foreach (['specs', 'specifiedBy', 'requires', 'isRequiredBy'] as $name) {
            $this->assertNull(Relation::where('name', $name)->value('reverse_id'), "{$name} 的反向關係被重新配回去了");
        }
    }

    #[TestDox('重跑部署 seed 不把後台刪掉的資料建回來')]
    public function test_deploy_seed_does_not_recreate_rows_deleted_in_the_admin()
    {
        $this->deploySeed();
        Scope::where('name', 'picture')->first()->delete();
        Relation::where('name', 'precedes')->first()->delete();

        $this->deploySeed();

        $this->assertSame(0, Scope::where('name', 'picture')->count());
        $this->assertSame(1, Scope::withTrashed()->where('name', 'picture')->count());
        $this->assertSame(0, Relation::where('name', 'precedes')->count());
        $this->assertSame(1, Relation::withTrashed()->where('name', 'precedes')->count());
    }

    #[TestDox('新加的預設資料會補進已有資料的資料庫')]
    public function test_deploy_seed_adds_new_reference_data_to_an_existing_database()
    {
        // 模擬「預設資料加進 seeder 之前就存在的資料庫」：用舊做法那支 migration 的 down()
        // 拿掉 isVersionOf／hasVersion，其他分類、述詞都在。
        $this->deploySeed();
        (require database_path('migrations/2026_09_30_190100_add_technique_version_relations.php'))->down();
        $this->assertSame(0, Relation::withTrashed()->whereIn('name', ['isVersionOf', 'hasVersion'])->count());
        $existingIds = Relation::orderBy('id')->pluck('id', 'name')->all();

        $this->deploySeed();

        $isVersionOf = Relation::where('name', 'isVersionOf')->firstOrFail();
        $hasVersion = Relation::where('name', 'hasVersion')->firstOrFail();
        $this->assertSame($hasVersion->id, $isVersionOf->reverse_id);
        $this->assertSame($isVersionOf->id, $hasVersion->reverse_id);
        $this->assertSame('dcterms', $isVersionOf->source_vocabulary);
        // 原本就在的不動
        $this->assertSame($existingIds, Relation::whereNotIn('name', ['isVersionOf', 'hasVersion'])->orderBy('id')->pluck('id', 'name')->all());
        $this->assertSame(23, Relation::count());
    }

    #[TestDox('外部參考用的分類、三組述詞與 Claude Code 技術節點會補進已有資料的資料庫')]
    public function test_deploy_seed_adds_external_reference_data_to_an_existing_database()
    {
        // 模擬這批預設資料加進 seeder 之前就存在的資料庫：先種一次，再把這批新的資料整批實體刪掉
        $this->deploySeed();
        $newRelations = ['about', 'subjectOf', 'targets', 'targetedBy', 'influences', 'influencedBy'];
        Technique::withTrashed()->where('title', 'Claude Code')->forceDelete();
        Relation::whereIn('name', $newRelations)->update(['reverse_id' => null]);
        Relation::whereIn('name', $newRelations)->forceDelete();
        Scope::where('name', 'repository')->forceDelete();
        $existingScopeIds = Scope::orderBy('id')->pluck('id', 'name')->all();
        $existingRelationIds = Relation::orderBy('id')->pluck('id', 'name')->all();

        $this->deploySeed();

        $repository = Scope::where('name', 'repository')->firstOrFail();
        $this->assertSame(Scope::where('name', 'Documentation')->value('id'), $repository->parent_class);
        $this->assertSame('0050', $repository->class_number.$repository->call_number);
        $this->assertSame(1, Technique::where('title', 'Claude Code')->count());
        $this->assertSame(Scope::where('name', 'assistant')->value('id'), Technique::where('title', 'Claude Code')->value('type'));
        foreach ([['about', 'subjectOf'], ['targets', 'targetedBy'], ['influences', 'influencedBy']] as [$forward, $reverse]) {
            $forwardRelation = Relation::where('name', $forward)->firstOrFail();
            $reverseRelation = Relation::where('name', $reverse)->firstOrFail();
            $this->assertSame($reverseRelation->id, $forwardRelation->reverse_id);
            $this->assertSame($forwardRelation->id, $reverseRelation->reverse_id);
        }
        // 原本就在的不動
        $this->assertSame($existingScopeIds, Scope::where('name', '!=', 'repository')->orderBy('id')->pluck('id', 'name')->all());
        $this->assertSame($existingRelationIds, Relation::whereNotIn('name', $newRelations)->orderBy('id')->pluck('id', 'name')->all());
    }

    #[TestDox('種出來的外部參考分類與述詞，位置與類號是設計的樣子')]
    public function test_external_reference_data_has_the_designed_numbers()
    {
        $this->deploySeed();

        // 名稱 => [類號＋子類號, 主詞頂層分類, 受詞頂層分類, 反向]
        $expected = [
            'about' => ['0110', 'Documentation', 'Technique', 'subjectOf'],
            'subjectOf' => ['1010', 'Technique', 'Documentation', 'about'],
            'targets' => ['0120', 'Documentation', 'Technique', 'targetedBy'],
            'targetedBy' => ['1020', 'Technique', 'Documentation', 'targets'],
            'influences' => ['0210', 'Documentation', 'Implementation', 'influencedBy'],
            'influencedBy' => ['2010', 'Implementation', 'Documentation', 'influences'],
        ];

        foreach ($expected as $name => [$number, $subject, $object, $reverse]) {
            $relation = Relation::where('name', $name)->firstOrFail();
            $this->assertSame($number, $relation->class_number.$relation->call_number, "{$name} 的類號");
            $this->assertSame($subject, Scope::find($relation->subject_id)->name, "{$name} 的主詞");
            $this->assertSame($object, Scope::find($relation->object_id)->name, "{$name} 的受詞");
            $this->assertSame($reverse, Relation::find($relation->reverse_id)->name, "{$name} 的反向");
        }

        // 類號加子類號在整張表裡不重複（新述詞、新分類沒有撞到既有的號碼）
        $numbers = Relation::all()->map(fn (Relation $relation) => $relation->class_number.$relation->call_number);
        $this->assertSame($numbers->count(), $numbers->unique()->count());
        $scopeNumbers = Scope::all()->map(fn (Scope $scope) => $scope->class_number.$scope->call_number);
        $this->assertSame($scopeNumbers->count(), $scopeNumbers->unique()->count());
    }

    #[TestDox('conversation 分類的說明寫明目前沒有使用')]
    public function test_conversation_scope_comment_says_it_is_unused()
    {
        $this->deploySeed();

        $comment = Scope::where('name', 'conversation')->value('comment');
        $this->assertStringContainsString('值得引用的具體 AI 對話紀錄', $comment);
        $this->assertStringContainsString('目前沒有使用', $comment);
    }

    #[TestDox('分類說明不超過資料表欄位的長度')]
    public function test_scope_comments_fit_the_column()
    {
        $this->deploySeed();

        // scopes.comment 是 tinyText，PostgreSQL 上是 varchar(255)，超過會讓正式環境的部署 seed 失敗
        foreach (Scope::all() as $scope) {
            $this->assertLessThanOrEqual(255, mb_strlen($scope->comment), "{$scope->name} 的 comment 太長");
        }
    }

    #[TestDox('後台刪掉的 Claude Code 技術節點，重跑部署 seed 不會建回來；改過的內容也不蓋掉')]
    public function test_deploy_seed_respects_admin_changes_to_the_claude_code_technique()
    {
        $this->deploySeed();
        Technique::where('title', 'Claude Code')->first()->update(['note' => '後台改過的註釋']);

        $this->deploySeed();

        $this->assertSame(1, Technique::count());
        $this->assertSame('後台改過的註釋', Technique::where('title', 'Claude Code')->value('note'));

        Technique::where('title', 'Claude Code')->first()->delete();

        $this->deploySeed();

        $this->assertSame(0, Technique::count());
        $this->assertSame(1, Technique::withTrashed()->where('title', 'Claude Code')->count());
    }

    #[TestDox('assistant 分類不在時不建 Claude Code，也不自己發明分類')]
    public function test_technique_seeder_does_not_invent_the_assistant_scope()
    {
        $this->deploySeed();
        Technique::where('title', 'Claude Code')->forceDelete();
        Scope::where('name', 'assistant')->first()->delete();

        $this->deploySeed();

        $this->assertSame(0, Technique::withTrashed()->count());
        $this->assertSame(1, Scope::withTrashed()->where('name', 'assistant')->count());
    }

    #[TestDox('seed 中途失敗時整個退回，不留一半的資料')]
    public function test_a_failing_seed_leaves_no_partial_data()
    {
        // 中途失敗（這裡用 Relation 存檔時丟錯模擬）要整個退回，部署流程停在這一步
        Relation::creating(fn () => throw new \RuntimeException('模擬失敗'));

        try {
            $this->seed(ReferenceDataSeeder::class);
            $this->fail('seeder 應該丟出例外');
        } catch (\RuntimeException $e) {
            $this->assertSame('模擬失敗', $e->getMessage());
        }

        $this->assertSame(0, DB::table('scopes')->count());
        $this->assertSame(0, DB::table('relations')->count());
    }
}
