<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * `/api/graph` 節點的 `subtype`、`deprecated`、`replaced_by`。
 *
 * 停用與合併的例子：Procfile 併進 Heroku、Hack 只停用。可見性照圖譜原本的規則：
 * 技術沒有草稿或不公開的狀態，訪客與登入看到的一樣；草稿文件的 subtype 只給登入的人，
 * 不公開的實作只給登入的人。
 */
class GraphTechniqueDeprecationTest extends TestCase
{
    use RefreshDatabase;

    private Technique $procfile;

    private Technique $heroku;

    private Technique $hack;

    private Documentation $draft;

    private Implementation $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $language = Scope::where('name', 'language')->value('id');
        $this->heroku = Technique::create(['type' => Scope::where('name', 'packagetool')->value('id'), 'title' => 'Heroku']);
        $this->procfile = Technique::create(['type' => $language, 'title' => 'Procfile']);
        $this->hack = Technique::create(['type' => $language, 'title' => 'Hack']);
        $this->procfile->deprecate($this->heroku);
        $this->hack->deprecate(null);

        $this->draft = Documentation::factory()->create(['type' => Scope::where('name', 'post')->value('id'), 'status' => 0]);
        $this->hidden = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id'), 'is_visible' => false]);
        $this->hidden->techniques()->attach($this->hack->id, ['relation_id' => Relation::where('name', 'usedBy')->value('id')]);
    }

    /** @return Collection<string, array<string, mixed>> */
    private function nodes(): Collection
    {
        return collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->keyBy('id');
    }

    #[TestDox('技術節點：子類是分類名稱；停用的帶 deprecated 與改用對象的節點 id，只停用的 replaced_by 是 null')]
    public function test_technique_nodes_carry_subtype_and_deprecation(): void
    {
        $nodes = $this->nodes();

        $this->assertSame(['language', true, "technique-{$this->heroku->id}"], $this->fields($nodes["technique-{$this->procfile->id}"]));
        $this->assertSame(['language', true, null], $this->fields($nodes["technique-{$this->hack->id}"]));
        $this->assertSame(['packagetool', false, null], $this->fields($nodes["technique-{$this->heroku->id}"]));
    }

    #[TestDox('改用對象也停用時給最後仍在使用的那一個；改用對象已刪除時是 null')]
    public function test_replaced_by_follows_the_chain_and_ignores_deleted_replacements(): void
    {
        $render = Technique::create(['type' => $this->heroku->type, 'title' => 'Render']);
        $this->heroku->deprecate($render);
        $gone = Technique::create(['type' => $this->heroku->type, 'title' => 'Gone']);
        $orphan = Technique::create(['type' => $this->heroku->type, 'title' => 'Orphan']);
        $orphan->deprecate($gone);
        $gone->delete();

        $nodes = $this->nodes();

        $this->assertSame("technique-{$render->id}", $nodes["technique-{$this->procfile->id}"]['replaced_by']);
        $this->assertSame("technique-{$render->id}", $nodes["technique-{$this->heroku->id}"]['replaced_by']);
        $this->assertSame([true, null], [$nodes["technique-{$orphan->id}"]['deprecated'], $nodes["technique-{$orphan->id}"]['replaced_by']]);
        $this->assertFalse($nodes->has("technique-{$gone->id}"));
    }

    #[TestDox('訪客與登入：技術欄位一樣；草稿文件的 subtype 只給登入的人；不公開的實作只給登入的人；文件與實作節點的 deprecated 一律 false')]
    public function test_visibility_follows_the_existing_graph_rules(): void
    {
        $techniqueIds = ["technique-{$this->procfile->id}", "technique-{$this->hack->id}", "technique-{$this->heroku->id}"];
        $guest = $this->nodes();
        $this->actingAsOwner();
        $owner = $this->nodes();

        foreach ($techniqueIds as $id) {
            $this->assertSame($this->fields($owner[$id]), $this->fields($guest[$id]), $id);
        }

        $draftId = "documentation-{$this->draft->id}";
        $this->assertSame([null, false, null], $this->fields($guest[$draftId]));
        $this->assertSame([$this->draft->scope->name, false, null], $this->fields($owner[$draftId]));
        $this->assertFalse($this->draft->isPublished());

        $hiddenId = "implementation-{$this->hidden->id}";
        $this->assertFalse($guest->has($hiddenId));
        $this->assertSame([null, false, null], $this->fields($owner[$hiddenId]));
    }

    #[TestDox('合併之後：停用的技術節點留著、沒有邊，邊都在改用對象上')]
    public function test_after_a_merge_edges_live_on_the_replacement(): void
    {
        $project = Implementation::factory()->create(['type' => Scope::where('name', 'project')->value('id'), 'is_visible' => true]);
        $usedBy = Relation::where('name', 'usedBy')->value('id');
        $fresh = Technique::create(['type' => Scope::where('name', 'language')->value('id'), 'title' => 'Procfile-legacy']);
        $project->techniques()->attach($fresh->id, ['relation_id' => $usedBy]);
        $this->actingAsOwner();

        $this->patchJson("/api/techniques/{$fresh->id}/deprecation", ['deprecated' => true, 'replaced_by_id' => $this->heroku->id])->assertOk();

        $graph = $this->getJson('/api/graph')->assertOk();
        $node = collect($graph->json('nodes'))->firstWhere('id', "technique-{$fresh->id}");
        $this->assertSame([true, "technique-{$this->heroku->id}"], [$node['deprecated'], $node['replaced_by']]);
        $edges = collect($graph->json('edges'));
        $this->assertFalse($edges->contains(fn (array $edge) => in_array("technique-{$fresh->id}", [$edge['source'], $edge['target']], true)));
        $this->assertTrue($edges->contains(fn (array $edge) => $edge['source'] === "technique-{$this->heroku->id}" && $edge['target'] === "implementation-{$project->id}"));
        $this->assertSame(0, DB::table('technique_implementation')->where('technique_id', $fresh->id)->count());
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function fields(array $node): array
    {
        return [$node['subtype'], $node['deprecated'], $node['replaced_by']];
    }
}
