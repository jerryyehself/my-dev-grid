<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 新的連結不能用已棄用的述詞，既有的連結照舊可以存（`RelationIsNotDeprecated`）。
 *
 * 六支 Store／Update 請求的每個連結欄位都跑一遍。編輯頁存檔會把整份連結清單重送，所以
 * 「重送既有的連結」要放行；「既有的連結改成棄用的述詞」與「新的連結」要擋。
 */
class RelationDeprecatedEdgeTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        'documentation' => Documentation::class,
        'technique' => Technique::class,
        'implementation' => Implementation::class,
    ];

    private Scope $scope;

    private Relation $live;

    private Relation $deprecated;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsOwner();

        // 兩端都用同一個分類，分類檢查（RelationFitsLinkedScopes）一律會過，只測棄用
        $this->scope = Scope::factory()->create(['name' => 'Anything', 'parent_class' => null]);
        $this->live = Relation::factory()->create(['name' => 'liveOne', 'subject_id' => $this->scope->id, 'object_id' => $this->scope->id]);
        $this->deprecated = Relation::factory()->create(['name' => 'oldOne', 'subject_id' => $this->scope->id, 'object_id' => $this->scope->id]);
        $this->deprecated->deprecate($this->live);
    }

    /** @return iterable<string, array{string, string}> */
    public static function linkFields(): iterable
    {
        foreach ([
            ['documentation', 'techniques'],
            ['documentation', 'implementations'],
            ['implementation', 'documentations'],
            ['implementation', 'techniques'],
            ['technique', 'documentations'],
            ['technique', 'implementations'],
        ] as [$own, $field]) {
            yield "{$own} {$field}" => [$own, $field];
        }
    }

    private function linked(string $field): Model
    {
        return self::MODELS[Str::singular($field)]::factory()->create(['type' => $this->scope->id]);
    }

    /** @param  list<array{id: int, relation_id: int}>  $links */
    private function payload(string $field, array $links): array
    {
        return ['type' => $this->scope->id, 'title' => 'Own title', $field => $links];
    }

    #[DataProvider('linkFields')]
    #[TestDox('新增實體時，用棄用述詞的連結回 422，並建議改用的述詞')]
    public function test_store_with_a_deprecated_relation_is_rejected(string $own, string $field)
    {
        $linked = $this->linked($field);
        $before = self::MODELS[$own]::count();

        $this->postJson('/api/'.Str::plural($own), $this->payload($field, [['id' => $linked->id, 'relation_id' => $this->deprecated->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["{$field}.0.relation_id" => "{$field}.0：述詞「oldOne」已棄用，請改用「liveOne」。"]);

        $this->assertSame($before, self::MODELS[$own]::count());
    }

    #[DataProvider('linkFields')]
    #[TestDox('新增實體時，用使用中的述詞照常建立')]
    public function test_store_with_a_live_relation_is_accepted(string $own, string $field)
    {
        $linked = $this->linked($field);

        $this->postJson('/api/'.Str::plural($own), $this->payload($field, [['id' => $linked->id, 'relation_id' => $this->live->id]]))
            ->assertCreated();
    }

    #[DataProvider('linkFields')]
    #[TestDox('更新時重送既有的、用棄用述詞的連結照常通過，連結保留')]
    public function test_update_resending_an_existing_deprecated_link_is_accepted(string $own, string $field)
    {
        $ownModel = self::MODELS[$own]::factory()->create(['type' => $this->scope->id]);
        $linked = $this->linked($field);
        $ownModel->{$field}()->attach($linked->id, ['relation_id' => $this->deprecated->id]);

        $this->putJson('/api/'.Str::plural($own)."/{$ownModel->id}", $this->payload($field, [['id' => $linked->id, 'relation_id' => $this->deprecated->id]]))
            ->assertOk();

        $this->assertSame([$linked->id => $this->deprecated->id], $this->links($ownModel, $field));
    }

    #[DataProvider('linkFields')]
    #[TestDox('更新時把既有的連結改成棄用述詞回 422，連結不變')]
    public function test_update_switching_an_existing_link_to_a_deprecated_relation_is_rejected(string $own, string $field)
    {
        $ownModel = self::MODELS[$own]::factory()->create(['type' => $this->scope->id]);
        $linked = $this->linked($field);
        $ownModel->{$field}()->attach($linked->id, ['relation_id' => $this->live->id]);

        $this->putJson('/api/'.Str::plural($own)."/{$ownModel->id}", $this->payload($field, [['id' => $linked->id, 'relation_id' => $this->deprecated->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor("{$field}.0.relation_id");

        $this->assertSame([$linked->id => $this->live->id], $this->links($ownModel, $field));
    }

    #[DataProvider('linkFields')]
    #[TestDox('更新時新增一條用棄用述詞的連結回 422；同一個實體的另一條既有連結不能讓它過')]
    public function test_update_adding_a_new_deprecated_link_is_rejected(string $own, string $field)
    {
        $ownModel = self::MODELS[$own]::factory()->create(['type' => $this->scope->id]);
        $existing = $this->linked($field);
        $new = $this->linked($field);
        $ownModel->{$field}()->attach($existing->id, ['relation_id' => $this->deprecated->id]);

        $this->putJson('/api/'.Str::plural($own)."/{$ownModel->id}", $this->payload($field, [
            ['id' => $existing->id, 'relation_id' => $this->deprecated->id],
            ['id' => $new->id, 'relation_id' => $this->deprecated->id],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor("{$field}.1.relation_id")
            ->assertJsonMissingValidationErrors("{$field}.0.relation_id");

        $this->assertSame([$existing->id => $this->deprecated->id], $this->links($ownModel, $field));
    }

    #[TestDox('別的實體的同一組連結不算「既有」')]
    public function test_a_link_on_another_entity_does_not_count_as_existing()
    {
        $other = Documentation::factory()->create(['type' => $this->scope->id]);
        $mine = Documentation::factory()->create(['type' => $this->scope->id]);
        $technique = Technique::factory()->create(['type' => $this->scope->id]);
        $other->techniques()->attach($technique->id, ['relation_id' => $this->deprecated->id]);

        $this->putJson("/api/documentations/{$mine->id}", $this->payload('techniques', [['id' => $technique->id, 'relation_id' => $this->deprecated->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('techniques.0.relation_id');
    }

    #[TestDox('沒有改用對象的棄用述詞，訊息只說已棄用')]
    public function test_message_without_a_replacement()
    {
        $bare = Relation::factory()->create(['name' => 'bareOne', 'subject_id' => $this->scope->id, 'object_id' => $this->scope->id]);
        $bare->deprecate(null);
        $technique = Technique::factory()->create(['type' => $this->scope->id]);

        $this->postJson('/api/documentations', $this->payload('techniques', [['id' => $technique->id, 'relation_id' => $bare->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['techniques.0.relation_id' => 'techniques.0：述詞「bareOne」已棄用，新的連結不能再用它。']);
    }

    /** @return array<int, int> 連到的實體 id => 述詞 id */
    private function links(Model $own, string $field): array
    {
        $relation = $own->{$field}();

        return DB::table($relation->getTable())
            ->where($relation->getForeignPivotKeyName(), $own->id)
            ->pluck('relation_id', $relation->getRelatedPivotKeyName())
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    #[TestDox('改用對象後來也棄用時，訊息建議最後仍在使用的那一條')]
    public function test_message_suggests_the_final_replacement()
    {
        $newer = Relation::factory()->create(['name' => 'newerOne', 'subject_id' => $this->scope->id, 'object_id' => $this->scope->id]);
        $this->live->deprecate($newer);
        $technique = Technique::factory()->create(['type' => $this->scope->id]);

        $this->postJson('/api/documentations', $this->payload('techniques', [['id' => $technique->id, 'relation_id' => $this->deprecated->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['techniques.0.relation_id' => 'techniques.0：述詞「oldOne」已棄用，請改用「newerOne」。']);
    }
}
