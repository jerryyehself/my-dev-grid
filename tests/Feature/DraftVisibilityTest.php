<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 草稿文章（status 0）對沒登入的請求不可見，登入後看得到。
 *
 * 讀取端點都是公開的，2026-09-30 之前草稿的標題與內文從 /api/documentations、/api/graph
 * 都拿得到，前端只是自己濾掉不顯示。每一支會帶出文章標題的公開端點都各守一條。
 *
 * 「登入」用 Sanctum::actingAs 模擬 my-dev-grid-front 的 Bearer token。匿名與登入分成
 * 不同的測試方法，免得同一個測試裡前一個請求的 guard 狀態殘留到下一個。
 */
class DraftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Documentation $published;

    private Documentation $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = Documentation::factory()->create(['title' => '已發布的文章', 'status' => 1]);
        $this->draft = Documentation::factory()->create(['title' => '草稿文章', 'status' => 0]);
    }

    private function signIn(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    #[TestDox('訪客的文件列表只看得到已發布文章')]
    public function test_guest_index_lists_only_published_documentations()
    {
        $this->getJson('/api/documentations')
            ->assertOk()
            ->assertJsonFragment(['title' => '已發布的文章'])
            ->assertJsonMissing(['title' => '草稿文章']);
    }

    #[TestDox('登入後的文件列表包含草稿')]
    public function test_signed_in_index_includes_drafts()
    {
        $this->signIn();

        $this->getJson('/api/documentations')
            ->assertOk()
            ->assertJsonFragment(['title' => '草稿文章']);
    }

    #[TestDox('訪客看草稿回 404，看已發布文章回 200')]
    public function test_guest_gets_404_for_a_draft_and_200_for_a_published_documentation()
    {
        $this->getJson("/api/documentations/{$this->draft->id}")->assertNotFound();
        $this->getJson("/api/documentations/{$this->published->id}")->assertOk();
    }

    #[TestDox('登入後可開啟草稿')]
    public function test_signed_in_can_open_a_draft()
    {
        $this->signIn();

        $this->getJson("/api/documentations/{$this->draft->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '草稿文章']);
    }

    #[TestDox('訪客的圖不含草稿節點與其邊')]
    public function test_guest_graph_drops_draft_nodes_and_their_edges()
    {
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        $this->draft->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $this->published->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->getJson('/api/graph')->assertOk();

        $nodeIds = collect($response->json('nodes'))->pluck('id');
        $this->assertContains("documentation-{$this->published->id}", $nodeIds);
        $this->assertNotContains("documentation-{$this->draft->id}", $nodeIds);

        $sources = collect($response->json('edges'))->pluck('source');
        $this->assertContains("documentation-{$this->published->id}", $sources);
        $this->assertNotContains("documentation-{$this->draft->id}", $sources);
        $response->assertJsonMissing(['label' => '草稿文章']);
    }

    #[TestDox('登入後的圖包含草稿節點')]
    public function test_signed_in_graph_includes_draft_nodes()
    {
        $this->signIn();

        $nodeIds = collect($this->getJson('/api/graph')->assertOk()->json('nodes'))->pluck('id');
        $this->assertContains("documentation-{$this->draft->id}", $nodeIds);
    }

    #[TestDox('訪客查經過草稿的路徑時找不到')]
    public function test_guest_path_to_or_through_a_draft_is_not_found()
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        // 技術 ↔ 草稿 ↔ 專案：唯一的路徑經過草稿
        $this->draft->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $this->draft->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);

        $this->getJson("/api/graph/path?start=documentation-{$this->draft->id}&end=technique-{$technique->id}")
            ->assertOk()
            ->assertJson(['found' => false])
            ->assertJsonMissing(['label' => '草稿文章']);

        $this->getJson("/api/graph/path?start=technique-{$technique->id}&end=implementation-{$implementation->id}")
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    #[TestDox('訪客檢視技術與實作時看不到草稿文件')]
    public function test_guest_technique_and_implementation_show_omit_draft_documentations()
    {
        $technique = Technique::factory()->create();
        $implementation = Implementation::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        foreach ([$this->draft, $this->published] as $documentation) {
            $documentation->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
            $documentation->implementations()->attach($implementation->id, ['relation_id' => $relation->id]);
        }

        $this->getJson("/api/techniques/{$technique->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '已發布的文章'])
            ->assertJsonMissing(['title' => '草稿文章']);

        $this->getJson("/api/implementations/{$implementation->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '已發布的文章'])
            ->assertJsonMissing(['title' => '草稿文章']);
    }

    #[TestDox('訪客的關係邊不含牽涉草稿的邊')]
    public function test_guest_relation_edges_omit_edges_touching_a_draft()
    {
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        $this->draft->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $this->published->techniques()->attach($technique->id, ['relation_id' => $relation->id]);

        $response = $this->getJson("/api/relations/{$relation->id}/edges")->assertOk();

        $this->assertSame(1, $response->json('total'));
        $response->assertJsonFragment(['subject_title' => '已發布的文章'])
            ->assertJsonMissing(['subject_title' => '草稿文章']);
    }

    #[TestDox('登入後的關係邊包含草稿')]
    public function test_signed_in_relation_edges_include_drafts()
    {
        $technique = Technique::factory()->create();
        $relation = Relation::factory()->create(['name' => 'specs']);
        $this->draft->techniques()->attach($technique->id, ['relation_id' => $relation->id]);
        $this->signIn();

        $this->getJson("/api/relations/{$relation->id}/edges")
            ->assertOk()
            ->assertJsonFragment(['subject_title' => '草稿文章']);
    }
}
