<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Technique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 設為不公開（`is_visible = false`）的專案對沒登入的請求不可見，登入後看得到。
 *
 * 讀取端點都是公開的，每一支會帶出專案、或帶出以專案為端點之連結的公開端點都各守一條，
 * 寫法跟 `DraftVisibilityTest` 一樣。「登入」用 Sanctum::actingAs 模擬 my-dev-grid-front
 * 的 Bearer token；另有一條用真的 token 走 Authorization 標頭，確認公開路由不掛
 * `auth:sanctum` 也認得出登入態。匿名與登入分成不同的測試方法，免得同一個測試裡前一個
 * 請求的 guard 狀態殘留到下一個。
 */
class ImplementationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Implementation $visible;

    private Implementation $hidden;

    private Technique $technique;

    private Documentation $documentation;

    private Relation $relation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->visible = Implementation::factory()->create(['title' => '公開的專案', 'is_visible' => true]);
        $this->hidden = Implementation::factory()->create(['title' => '不公開的專案', 'is_visible' => false]);
        $this->technique = Technique::factory()->create(['title' => '某個技術', 'version' => null]);
        $this->documentation = Documentation::factory()->create(['title' => '已發布的文章', 'status' => 1]);
        $this->relation = Relation::factory()->create(['name' => 'uses']);

        // 兩個專案都接到同一個技術與同一篇文章，再加一條專案之間的邊（entity_relations）
        foreach ([$this->visible, $this->hidden] as $implementation) {
            $implementation->techniques()->attach($this->technique->id, ['relation_id' => $this->relation->id]);
            $implementation->documentations()->attach($this->documentation->id, ['relation_id' => $this->relation->id]);
        }
        EntityRelation::create([
            'entity_type' => 'implementation',
            'subject_id' => $this->hidden->id,
            'object_id' => $this->visible->id,
            'relation_id' => $this->relation->id,
        ]);
    }

    private function signIn(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function hiddenNodeId(): string
    {
        return "implementation-{$this->hidden->id}";
    }

    #[TestDox('訪客的實作列表不含不公開的專案，也沒有 is_visible')]
    public function test_guest_index_omits_hidden_implementations_and_the_is_visible_field()
    {
        $response = $this->getJson('/api/implementations')
            ->assertOk()
            ->assertJsonFragment(['title' => '公開的專案'])
            ->assertJsonMissing(['title' => '不公開的專案']);

        $this->assertArrayNotHasKey('is_visible', $response->json('data.0'));
    }

    #[TestDox('登入後的實作列表包含不公開的專案與 is_visible')]
    public function test_signed_in_index_includes_hidden_implementations_with_is_visible()
    {
        $this->signIn();

        $this->getJson('/api/implementations')
            ->assertOk()
            ->assertJsonFragment(['title' => '不公開的專案', 'is_visible' => false])
            ->assertJsonFragment(['title' => '公開的專案', 'is_visible' => true]);
    }

    #[TestDox('訪客看不公開的專案回 404，看公開的回 200 且沒有 is_visible')]
    public function test_guest_gets_404_for_a_hidden_implementation_and_200_for_a_visible_one()
    {
        $this->getJson("/api/implementations/{$this->hidden->id}")->assertNotFound();

        $response = $this->getJson("/api/implementations/{$this->visible->id}")->assertOk();
        $this->assertArrayNotHasKey('is_visible', $response->json());
    }

    #[TestDox('登入後可開啟不公開的專案，回應含 is_visible')]
    public function test_signed_in_can_open_a_hidden_implementation()
    {
        $this->signIn();

        $this->getJson("/api/implementations/{$this->hidden->id}")
            ->assertOk()
            ->assertJsonPath('title', '不公開的專案')
            ->assertJsonPath('is_visible', false);
    }

    #[TestDox('帶有效 token 的公開請求看得到不公開的專案')]
    public function test_a_real_bearer_token_on_the_public_route_sees_hidden_implementations()
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/implementations/{$this->hidden->id}")
            ->assertOk()
            ->assertJsonPath('is_visible', false);
    }

    #[TestDox('無效的 token 當成訪客，不是 401')]
    public function test_an_invalid_token_is_treated_as_a_guest_not_rejected()
    {
        $this->withToken('1|not-a-real-token')
            ->getJson('/api/implementations')
            ->assertOk()
            ->assertJsonMissing(['title' => '不公開的專案']);
    }

    #[TestDox('訪客的圖不含不公開的專案節點與連到它的邊')]
    public function test_guest_graph_drops_hidden_implementation_nodes_and_their_edges()
    {
        $response = $this->getJson('/api/graph')->assertOk();

        $nodeIds = collect($response->json('nodes'))->pluck('id');
        $this->assertContains("implementation-{$this->visible->id}", $nodeIds);
        $this->assertNotContains($this->hiddenNodeId(), $nodeIds);

        $edges = collect($response->json('edges'));
        $this->assertNotEmpty($edges);
        $this->assertNotContains($this->hiddenNodeId(), $edges->pluck('source'));
        $this->assertNotContains($this->hiddenNodeId(), $edges->pluck('target'));
        $response->assertJsonMissing(['label' => '不公開的專案']);
    }

    #[TestDox('登入後的圖包含不公開的專案節點與它的邊')]
    public function test_signed_in_graph_includes_hidden_implementation_nodes_and_edges()
    {
        $this->signIn();

        $response = $this->getJson('/api/graph')->assertOk();

        $this->assertContains($this->hiddenNodeId(), collect($response->json('nodes'))->pluck('id'));
        $edges = collect($response->json('edges'));
        // 技術、文章各一條，加上專案之間那一條
        $this->assertSame(3, $edges->filter(fn (array $edge) => in_array($this->hiddenNodeId(), [$edge['source'], $edge['target']], true))->count());
    }

    #[TestDox('訪客以不公開的專案為起點查路徑時找不到')]
    public function test_guest_path_starting_at_a_hidden_implementation_is_not_found()
    {
        $this->getJson("/api/graph/path?start={$this->hiddenNodeId()}&end=technique-{$this->technique->id}")
            ->assertOk()
            ->assertJson(['found' => false, 'nodes' => [], 'edges' => []])
            ->assertJsonMissing(['label' => '不公開的專案']);
    }

    #[TestDox('訪客查路徑時不會經過不公開的專案')]
    public function test_guest_path_does_not_go_through_a_hidden_implementation()
    {
        // 另一個技術只接到不公開的專案：唯一的路徑經過它
        $isolated = Technique::factory()->create();
        $this->hidden->techniques()->attach($isolated->id, ['relation_id' => $this->relation->id]);

        $this->getJson("/api/graph/path?start=technique-{$isolated->id}&end=technique-{$this->technique->id}")
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    #[TestDox('登入後查路徑可以經過不公開的專案')]
    public function test_signed_in_path_can_go_through_a_hidden_implementation()
    {
        $isolated = Technique::factory()->create();
        $this->hidden->techniques()->attach($isolated->id, ['relation_id' => $this->relation->id]);
        $this->signIn();

        $response = $this->getJson("/api/graph/path?start=technique-{$isolated->id}&end=technique-{$this->technique->id}")
            ->assertOk()
            ->assertJson(['found' => true]);

        $this->assertContains($this->hiddenNodeId(), collect($response->json('nodes'))->pluck('id'));
    }

    #[TestDox('訪客的關係邊不含牽涉不公開專案的邊')]
    public function test_guest_relation_edges_omit_edges_touching_a_hidden_implementation()
    {
        $response = $this->getJson("/api/relations/{$this->relation->id}/edges")->assertOk();

        // 公開的專案接技術、接文章各一條；不公開的那兩條和專案之間那一條都不列
        $this->assertSame(2, $response->json('total'));
        $response->assertJsonMissing(['subject_title' => '不公開的專案'])
            ->assertJsonMissing(['object_title' => '不公開的專案']);
    }

    #[TestDox('登入後的關係邊包含不公開的專案')]
    public function test_signed_in_relation_edges_include_hidden_implementations()
    {
        $this->signIn();

        $response = $this->getJson("/api/relations/{$this->relation->id}/edges")->assertOk();

        $this->assertSame(5, $response->json('total'));
        $response->assertJsonFragment(['subject_title' => '不公開的專案']);
    }

    #[TestDox('訪客檢視文件與技術時看不到不公開的專案')]
    public function test_guest_documentation_and_technique_reads_omit_hidden_implementations()
    {
        $this->getJson('/api/documentations')
            ->assertOk()
            ->assertJsonFragment(['title' => '公開的專案'])
            ->assertJsonMissing(['title' => '不公開的專案']);

        $this->getJson("/api/documentations/{$this->documentation->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '公開的專案'])
            ->assertJsonMissing(['title' => '不公開的專案']);

        $this->getJson("/api/techniques/{$this->technique->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '公開的專案'])
            ->assertJsonMissing(['title' => '不公開的專案']);
    }

    #[TestDox('登入後檢視文件與技術時看得到不公開的專案')]
    public function test_signed_in_documentation_and_technique_reads_include_hidden_implementations()
    {
        $this->signIn();

        $this->getJson("/api/documentations/{$this->documentation->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '不公開的專案']);

        $this->getJson("/api/techniques/{$this->technique->id}")
            ->assertOk()
            ->assertJsonFragment(['title' => '不公開的專案']);
    }

    #[TestDox('公開讀取的回應不讓共用快取保存')]
    public function test_public_graph_response_is_not_cacheable_by_shared_caches()
    {
        // 同一個網址，登入與否回應不同；回應能被 CDN 之類的共用快取存下來的話，
        // 登入者看到的不公開專案就可能被轉給下一個訪客。
        $cacheControl = $this->getJson('/api/graph')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
    }
}
