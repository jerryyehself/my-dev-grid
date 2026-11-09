<?php

namespace Tests\Feature;

use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 連結的分類檢查（見 `RelationScopeCheckTest`）套在種子資料的分類與述詞上：
 * 實體多半掛在子分類（post、framework、project），述詞的主詞受詞是頂層分類，所以靠子分類才過得了。
 *
 * 另開一個類別，是因為 `RelationSeeder` 用 class_number 找頂層分類，
 * 測試自己先建的分類會被它撈走。
 */
class RelationScopeSeededDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAsOwner();
    }

    private function scopeId(string $name): int
    {
        return Scope::where('name', $name)->firstOrFail()->id;
    }

    private function relationId(string $name): int
    {
        return Relation::where('name', $name)->firstOrFail()->id;
    }

    #[TestDox('文件（post）可用 specs 連到技術（framework），反向的 specifiedBy 不行')]
    public function test_documentation_to_technique(): void
    {
        $technique = Technique::factory()->create(['type' => $this->scopeId('framework')]);

        $this->postJson('/api/documentations', [
            'type' => $this->scopeId('post'),
            'title' => 'Spec post',
            'techniques' => [['id' => $technique->id, 'relation_id' => $this->relationId('specs')]],
        ])->assertCreated();

        $this->postJson('/api/documentations', [
            'type' => $this->scopeId('post'),
            'title' => 'Wrong direction',
            'techniques' => [['id' => $technique->id, 'relation_id' => $this->relationId('specifiedBy')]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
    }

    #[TestDox('文件（post）可用 documents 連到實作（project），反向的 documentedBy 不行')]
    public function test_documentation_to_implementation(): void
    {
        $implementation = Implementation::factory()->create(['type' => $this->scopeId('project')]);

        $this->postJson('/api/documentations', [
            'type' => $this->scopeId('post'),
            'title' => 'Doc post',
            'implementations' => [['id' => $implementation->id, 'relation_id' => $this->relationId('documents')]],
        ])->assertCreated();

        $this->postJson('/api/documentations', [
            'type' => $this->scopeId('post'),
            'title' => 'Wrong direction',
            'implementations' => [['id' => $implementation->id, 'relation_id' => $this->relationId('documentedBy')]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['implementations.0.relation_id']);
    }

    #[TestDox('技術（framework）可用 usedBy 連到實作（project），反向的 uses 不行')]
    public function test_technique_to_implementation(): void
    {
        $implementation = Implementation::factory()->create(['type' => $this->scopeId('project')]);

        $this->postJson('/api/techniques', [
            'type' => $this->scopeId('framework'),
            'title' => 'Some framework',
            'implementations' => [['id' => $implementation->id, 'relation_id' => $this->relationId('usedBy')]],
        ])->assertCreated();

        $this->postJson('/api/techniques', [
            'type' => $this->scopeId('framework'),
            'title' => 'Another framework',
            'implementations' => [['id' => $implementation->id, 'relation_id' => $this->relationId('uses')]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['implementations.0.relation_id']);
    }
}
