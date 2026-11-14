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

    #[TestDox('文件（repository）可用 targets 連到技術（assistant），influences 連到實作（project）；反向與接錯對象的都不行')]
    public function test_external_reference_predicates(): void
    {
        // 種出來的 Claude Code 就是外部參考要連的那個技術節點
        $claudeCode = Technique::where('title', 'Claude Code')->firstOrFail();
        $implementation = Implementation::factory()->create(['type' => $this->scopeId('project')]);

        $this->postJson('/api/documentations', [
            'type' => $this->scopeId('repository'),
            'title' => 'Repository targets',
            'techniques' => [['id' => $claudeCode->id, 'relation_id' => $this->relationId('targets')]],
        ])->assertCreated();

        $this->postJson('/api/documentations', [
            'type' => $this->scopeId('repository'),
            'title' => 'Repository influences',
            'implementations' => [['id' => $implementation->id, 'relation_id' => $this->relationId('influences')]],
        ])->assertCreated();

        // 反向的三個述詞，以及接到另一種對象（targets／about 接實作、influences 接技術），都要被擋下來
        foreach (['subjectOf', 'targetedBy', 'influencedBy', 'influences'] as $name) {
            $this->postJson('/api/documentations', [
                'type' => $this->scopeId('repository'),
                'title' => "Wrong technique {$name}",
                'techniques' => [['id' => $claudeCode->id, 'relation_id' => $this->relationId($name)]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['techniques.0.relation_id']);
        }

        foreach (['subjectOf', 'targetedBy', 'influencedBy', 'about', 'targets'] as $name) {
            $this->postJson('/api/documentations', [
                'type' => $this->scopeId('repository'),
                'title' => "Wrong implementation {$name}",
                'implementations' => [['id' => $implementation->id, 'relation_id' => $this->relationId($name)]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['implementations.0.relation_id']);
        }
    }
}
