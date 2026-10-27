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

    #[TestDox('部署時只跑分類與述詞兩支 seeder')]
    public function test_only_reference_data_seeders_run_on_deploy()
    {
        // 加進來的 seeder 每次部署都會在正式環境跑，要先確認符合 ReferenceDataSeeder 的條件
        $this->assertSame([ScopeSeeder::class, RelationSeeder::class], ReferenceDataSeeder::SEEDERS);
    }

    #[TestDox('空資料庫跑部署 seed 只建出預設資料')]
    public function test_deploy_seed_on_an_empty_database_creates_the_reference_data_only()
    {
        Http::preventStrayRequests();

        $this->deploySeed();

        $this->assertSame(16, Scope::count());
        $this->assertSame(17, Relation::count());
        // 不建帳號、不碰內容資料
        $this->assertSame(0, User::count());
        $this->assertSame(0, Documentation::count());
        $this->assertSame(0, Technique::count());
        $this->assertSame(0, Implementation::count());
        $this->assertSame(0, EntityRelation::count());
    }

    #[TestDox('部署 seed 跑兩次不會多出資料')]
    public function test_running_the_deploy_seed_twice_creates_no_duplicates()
    {
        $this->deploySeed();
        $this->deploySeed();

        $this->assertSame(16, Scope::count());
        $this->assertSame(17, Relation::count());
        $this->assertSame(16, Scope::withTrashed()->distinct()->count('name'));
        $this->assertSame(17, Relation::withTrashed()->distinct()->count('name'));
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
        $this->assertSame(17, Relation::count());
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
