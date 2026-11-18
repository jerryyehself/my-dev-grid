<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\SaveReposDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 站主指定專案與技術之間的述詞（`PATCH /api/implementations/{implementation}/techniques/{technique}`）、
 * 交還給同步（`DELETE …/manual`），以及編輯頁（`PUT /api/implementations/{id}`）改述詞時一樣標成站主指定。
 * 標過的邊，同步不改述詞、也不在旁邊另長一列。
 */
class ImplementationTechniquePredicateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-19 03:17:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 語言統計有 PHP（程式證據），topic 有 laravel（只有 topic 證據）。 */
    private function sync(): Implementation
    {
        (new SaveReposDataService(collect([[
            'id' => 111,
            'git_repo_id' => 111,
            'title' => 'demo',
            'html_url' => 'https://github.com/example/demo',
            'description' => null,
            'topics' => ['laravel'],
            'languages' => ['PHP'],
            'language_bytes' => ['PHP' => 1000],
            'packages' => [],
            'package_versions' => [],
            'created_at' => '2026-06-15T00:00:00Z',
            'archived' => false,
            'owner' => 'example',
        ]])))->save_repos_data();

        return Implementation::where('git_repo_id', 111)->firstOrFail();
    }

    private function relationId(string $name): int
    {
        return (int) Relation::where('name', $name)->value('id');
    }

    private function technique(string $title): Technique
    {
        return Technique::whereRaw('lower(title) = ?', [mb_strtolower($title)])->whereNull('version')->firstOrFail();
    }

    /** @return list<array{relation: string, manual: bool}> 這一對所有列，依 id 排序 */
    private function pairRows(Implementation $project, Technique $technique): array
    {
        return DB::table('technique_implementation')
            ->join('relations', 'relations.id', '=', 'technique_implementation.relation_id')
            ->where(['implementation_id' => $project->id, 'technique_id' => $technique->id])
            ->orderBy('technique_implementation.id')
            ->get(['relations.name', 'technique_implementation.manually_set_at'])
            ->map(fn (object $row) => ['relation' => $row->name, 'manual' => $row->manually_set_at !== null])
            ->all();
    }

    private function patchUrl(Implementation $project, Technique $technique): string
    {
        return "/api/implementations/{$project->id}/techniques/{$technique->id}";
    }

    #[TestDox('PATCH 把有程式證據的邊改成 subjectOfProject 之後，同步照樣保留站主選的，旁邊也不多長 usedBy')]
    public function test_owner_choice_survives_sync(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        $this->actingAsOwner();

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('subjectOfProject')])
            ->assertOk()
            ->assertJsonPath('message', 'Implementation updated.');
        $this->assertSame([['relation' => 'subjectOfProject', 'manual' => true]], $this->pairRows($project, $php));
        $this->assertSame('2026-11-19 03:17:00', Carbon::parse(DB::table('technique_implementation')->where('technique_id', $php->id)->value('manually_set_at'))->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-20 03:17:00');
        $this->sync();

        $this->assertSame([['relation' => 'subjectOfProject', 'manual' => true]], $this->pairRows($project, $php));
    }

    #[TestDox('站主改成別的述詞（assists）之後，同步也不在旁邊另寫 usedBy；再 PATCH 一次改的是同一列')]
    public function test_owner_choice_of_another_predicate_is_left_alone(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        $this->actingAsOwner();

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('assists')])->assertOk();
        $this->sync();
        $this->assertSame([['relation' => 'assists', 'manual' => true]], $this->pairRows($project, $php));

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('usedBy')])->assertOk();
        $this->assertSame([['relation' => 'usedBy', 'manual' => true]], $this->pairRows($project, $php));
    }

    #[TestDox('DELETE …/manual 交還之後，同步可以把站主改成 subjectOfProject 的邊升回 usedBy')]
    public function test_released_edge_is_upgraded_by_sync(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        $this->actingAsOwner();
        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('subjectOfProject')])->assertOk();

        $this->deleteJson($this->patchUrl($project, $php).'/manual')->assertOk();
        $this->assertSame([['relation' => 'subjectOfProject', 'manual' => false]], $this->pairRows($project, $php));

        $this->sync();

        $this->assertSame([['relation' => 'usedBy', 'manual' => false]], $this->pairRows($project, $php));
    }

    #[TestDox('DELETE …/manual：沒有標記時照樣 200、不改資料；這一對沒有邊回 404')]
    public function test_release_is_idempotent_and_404s_without_an_edge(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        $other = Technique::factory()->create(['type' => Scope::where('name', 'language')->value('id')]);
        $this->actingAsOwner();

        $this->deleteJson($this->patchUrl($project, $php).'/manual')->assertOk();
        $this->assertSame([['relation' => 'usedBy', 'manual' => false]], $this->pairRows($project, $php));

        $this->deleteJson($this->patchUrl($project, $other).'/manual')->assertNotFound();
    }

    #[TestDox('沒登入：PATCH 與 DELETE 都 401，資料不變')]
    public function test_guest_gets_401(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('subjectOfProject')])->assertUnauthorized();
        $this->deleteJson($this->patchUrl($project, $php).'/manual')->assertUnauthorized();

        $this->assertSame([['relation' => 'usedBy', 'manual' => false]], $this->pairRows($project, $php));
    }

    #[TestDox('PATCH：這一對沒有邊回 404，不新增邊')]
    public function test_patch_404s_without_an_edge(): void
    {
        $project = $this->sync();
        $other = Technique::factory()->create(['type' => Scope::where('name', 'language')->value('id')]);
        $this->actingAsOwner();

        $this->patchJson($this->patchUrl($project, $other), ['relation_id' => $this->relationId('subjectOfProject')])->assertNotFound();

        $this->assertSame([], $this->pairRows($project, $other));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRelations(): iterable
    {
        yield '分類不符（文件 → 技術的 about）' => ['about'];
        yield '反向（實作 → 技術的 uses）' => ['uses'];
        yield '已棄用' => ['deprecated'];
        yield '已刪除' => ['trashed'];
        yield '不存在' => ['missing'];
        yield '不是整數' => ['abc'];
        yield '多帶 type：about 加上 type abc' => ['about', 'abc'];
        yield '多帶 type：requires 加上語言分類的 id' => ['requires', 'language'];
    }

    #[DataProvider('invalidRelations')]
    #[TestDox('PATCH：述詞分類不符、已棄用、已刪除、不存在或格式不對都 422，資料不變')]
    public function test_patch_rejects_invalid_relations(string $case, ?string $extraType = null): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        $this->actingAsOwner();

        $relationId = match ($case) {
            'deprecated' => $this->deprecatedTechniqueToImplementationRelation(),
            'trashed' => tap(Relation::factory()->create(), fn (Relation $r) => $r->delete())->id,
            'missing' => 999999,
            'abc' => 'abc',
            default => $this->relationId($case),
        };

        $payload = ['relation_id' => $relationId];
        if ($extraType !== null) {
            // 多帶的 type 不能影響分類檢查（'language' 代表語言分類的 id）
            $payload['type'] = $extraType === 'language' ? Scope::where('name', 'language')->value('id') : $extraType;
        }

        $this->patchJson($this->patchUrl($project, $php), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['relation_id']);

        $this->assertSame([['relation' => 'usedBy', 'manual' => false]], $this->pairRows($project, $php));
    }

    #[TestDox('PATCH：同一對已經有另一列是這個述詞時 422，兩列都不變')]
    public function test_patch_rejects_a_predicate_another_row_of_the_pair_already_has(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        DB::table('technique_implementation')->insert([
            'implementation_id' => $project->id,
            'technique_id' => $php->id,
            'relation_id' => $this->relationId('assists'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsOwner();

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('assists')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['relation_id']);

        $this->assertSame([
            ['relation' => 'usedBy', 'manual' => false],
            ['relation' => 'assists', 'manual' => false],
        ], $this->pairRows($project, $php));
    }

    #[TestDox('PATCH：同一對有兩列，改的是同步管理的那一列（usedBy），手動建的 assists 不動')]
    public function test_patch_targets_the_synced_row(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        DB::table('technique_implementation')->insert([
            'implementation_id' => $project->id,
            'technique_id' => $php->id,
            'relation_id' => $this->relationId('assists'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsOwner();

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('subjectOfProject')])->assertOk();

        $this->assertSame([
            ['relation' => 'subjectOfProject', 'manual' => true],
            ['relation' => 'assists', 'manual' => false],
        ], $this->pairRows($project, $php));
    }

    #[TestDox('PATCH：同一對同時有 usedBy 與 subjectOfProject 兩列（例如退版期間舊程式多寫了一列），分不出要改哪一條，回 409')]
    public function test_patch_409s_when_the_target_row_is_ambiguous(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        DB::table('technique_implementation')->insert([
            'implementation_id' => $project->id,
            'technique_id' => $php->id,
            'relation_id' => $this->relationId('subjectOfProject'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsOwner();

        $this->patchJson($this->patchUrl($project, $php), ['relation_id' => $this->relationId('assists')])->assertConflict();

        $this->assertSame([
            ['relation' => 'usedBy', 'manual' => false],
            ['relation' => 'subjectOfProject', 'manual' => false],
        ], $this->pairRows($project, $php));
    }

    #[TestDox('PUT /api/implementations 改了技術的述詞或新增技術，那幾列標成站主指定；述詞沒變的列不標')]
    public function test_put_flags_changed_and_attached_rows_only(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        $laravel = $this->technique('laravel');
        $vue = Technique::factory()->create(['type' => Scope::where('name', 'framework')->value('id'), 'title' => 'Vue']);
        $this->actingAsOwner();

        $this->putJson("/api/implementations/{$project->id}", [
            'type' => $project->type,
            'title' => $project->title,
            'techniques' => [
                ['id' => $php->id, 'relation_id' => $this->relationId('usedBy')],
                ['id' => $laravel->id, 'relation_id' => $this->relationId('usedBy')],
                ['id' => $vue->id, 'relation_id' => $this->relationId('subjectOfProject')],
            ],
        ])->assertOk();

        $this->assertSame([['relation' => 'usedBy', 'manual' => false]], $this->pairRows($project, $php));
        $this->assertSame([['relation' => 'usedBy', 'manual' => true]], $this->pairRows($project, $laravel));
        $this->assertSame([['relation' => 'subjectOfProject', 'manual' => true]], $this->pairRows($project, $vue));

        // 之後的同步不把 laravel 改回去（它仍只有 topic 證據）
        $this->sync();
        $this->assertSame([['relation' => 'usedBy', 'manual' => true]], $this->pairRows($project, $laravel));
    }

    #[TestDox('從技術那一側（PUT /api/techniques）改述詞也標成站主指定')]
    public function test_put_from_the_technique_side_flags_too(): void
    {
        $project = $this->sync();
        $laravel = $this->technique('laravel');
        $this->actingAsOwner();

        $this->putJson("/api/techniques/{$laravel->id}", [
            'type' => $laravel->type,
            'title' => $laravel->title,
            'implementations' => [['id' => $project->id, 'relation_id' => $this->relationId('usedBy')]],
        ])->assertOk();

        $this->assertSame([['relation' => 'usedBy', 'manual' => true]], $this->pairRows($project, $laravel));
    }

    #[TestDox('manually_set_at 只在登入時出現在 API 回應；訪客看不到')]
    public function test_manually_set_at_is_only_shown_to_signed_in_viewers(): void
    {
        $project = $this->sync();
        $php = $this->technique('PHP');
        DB::table('technique_implementation')->where('technique_id', $php->id)->update(['manually_set_at' => now()]);

        $guest = $this->getJson("/api/implementations/{$project->id}")->assertOk();
        foreach ($guest->json('techniques') as $technique) {
            $this->assertArrayNotHasKey('manually_set_at', $technique);
        }
        foreach ($this->getJson("/api/techniques/{$php->id}")->assertOk()->json('implementations') as $implementation) {
            $this->assertArrayNotHasKey('manually_set_at', $implementation);
        }

        $this->actingAsOwner();
        $techniques = collect($this->getJson("/api/implementations/{$project->id}")->assertOk()->json('techniques'))->keyBy('id');
        $this->assertSame('2026-11-19 03:17:00', $techniques[$php->id]['manually_set_at']);
        $this->assertNull($techniques[$this->technique('laravel')->id]['manually_set_at']);
        $implementations = collect($this->getJson("/api/techniques/{$php->id}")->assertOk()->json('implementations'))->keyBy('id');
        $this->assertSame('2026-11-19 03:17:00', $implementations[$project->id]['manually_set_at']);
    }

    /** 一條主詞是技術、受詞是實作，但已棄用的述詞（改用 subjectOfProject）。 */
    private function deprecatedTechniqueToImplementationRelation(): int
    {
        $about = Relation::where('name', 'subjectOfProject')->firstOrFail();
        $old = Relation::factory()->create(['subject_id' => $about->subject_id, 'object_id' => $about->object_id, 'class_number' => '88']);
        $old->forceFill(['deprecated_at' => now(), 'replaced_by_id' => $about->id])->save();

        return $old->id;
    }
}
