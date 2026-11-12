<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 文章的匯入來源欄位（`documentations.source_*`）只給匯入程式用，不出現在任何 API 回應裡，
 * 也不能從 API 寫入。
 *
 * 文章會從好幾條路由帶出來（文章本身、技術與實作的詳情、圖譜、最短路徑、述詞的邊），
 * 所以這裡打遍 `api/` 底下每一條 GET 路由，而不是只打文章的端點。路由表裡多了一條
 * GET 路由、這裡沒有對應的網址，測試就失敗，要回來補。
 *
 * 只比對這七個欄位的完整名稱：`relations` 也有 `source_vocabulary`、`source_term`，
 * 那兩個是公開的。
 */
class DocumentationSourceColumnsHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** 欄位名稱 => 夾具裡的值。值都是獨一無二的字串，回應裡出現就是洩漏。 */
    private const SOURCE_VALUES = [
        'source_repo' => 'srcmark-repo',
        'source_key' => 'srcmark-key',
        'source_path' => 'srcmark-dir/srcmark-file.md',
        'source_blob_sha' => 'b10bb10bb10bb10bb10bb10bb10bb10bb10bb10b',
        'source_commit_sha' => 'c0ffeec0ffeec0ffeec0ffeec0ffeec0ffeec0ff',
        'source_imported_at' => '2001-02-03 04:05:06',
        'source_body_hash' => 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface',
    ];

    private const TITLE = 'srcmark-documentation-title';

    /** 回應裡一定要出現文章標題的路由：證明文章真的有被序列化，上面的檢查不是空轉。 */
    private const ROUTES_THAT_SERIALIZE_THE_DOCUMENTATION = [
        'api/documentations',
        'api/documentations/{documentation}',
        'api/techniques/{technique}',
        'api/implementations/{implementation}',
        'api/relations/{relation}/edges',
        'api/graph',
        'api/graph/path',
    ];

    private Documentation $documentation;

    private Technique $technique;

    private Implementation $implementation;

    private Relation $relation;

    private Scope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scope = Scope::factory()->create();
        $this->relation = Relation::factory()->create(['subject_id' => $this->scope->id, 'object_id' => $this->scope->id]);
        $this->documentation = Documentation::factory()->withBody()->create([
            'type' => $this->scope->id,
            'title' => self::TITLE,
            'status' => Documentation::STATUS_PUBLISHED,
            ...self::SOURCE_VALUES,
        ]);
        $this->technique = Technique::factory()->create(['type' => $this->scope->id]);
        $this->implementation = Implementation::factory()->create(['type' => $this->scope->id, 'is_visible' => true]);
        $this->documentation->techniques()->attach($this->technique->id, ['relation_id' => $this->relation->id]);
        $this->documentation->implementations()->attach($this->implementation->id, ['relation_id' => $this->relation->id]);

        // 夾具真的寫進去了，不然下面「回應裡沒有這些值」是空轉
        $this->assertSame(self::SOURCE_VALUES['source_key'], $this->documentation->fresh()->source_key);
    }

    #[TestDox('每條 api 的 GET 路由在這裡都有對應的網址，沒有多餘的項目')]
    public function test_every_api_get_route_has_a_url_here(): void
    {
        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();
        $declared = array_keys($this->getUrls());

        $this->assertSame([], array_values(array_diff($actual, $declared)), '這些 GET 路由還沒列進 getUrls()');
        $this->assertSame([], array_values(array_diff($declared, $actual)), 'getUrls() 裡這些項目已經沒有對應的路由');
    }

    #[TestDox('訪客讀每條公開的 GET 路由，回應都不含來源欄位')]
    public function test_guest_responses_never_contain_source_columns(): void
    {
        $serialized = [];

        foreach ($this->getUrls() as $uri => $url) {
            if ($uri === 'api/user') {
                $this->getJson($url)->assertUnauthorized();

                continue;
            }

            $response = $this->getJson($url)->assertOk();
            $this->assertNoSourceColumns($response, "訪客 GET {$url}");
            if (str_contains($response->getContent(), self::TITLE)) {
                $serialized[] = $uri;
            }
        }

        foreach (self::ROUTES_THAT_SERIALIZE_THE_DOCUMENTATION as $uri) {
            $this->assertContains($uri, $serialized, "{$uri} 的回應裡沒有夾具文章，檢查可能空轉");
        }
    }

    #[TestDox('登入後讀每條 GET 路由，回應也不含來源欄位')]
    public function test_signed_in_responses_never_contain_source_columns(): void
    {
        $this->actingAsOwner();

        foreach ($this->getUrls() as $url) {
            $this->assertNoSourceColumns($this->getJson($url)->assertOk(), "登入 GET {$url}");
        }
    }

    #[TestDox('新增與更新文章時帶來源欄位會被忽略，回應也不含來源欄位')]
    public function test_store_and_update_ignore_source_columns(): void
    {
        $this->actingAsOwner();
        $attempt = [
            'source_repo' => 'attempt/repo',
            'source_key' => 'attempt-key',
            'source_path' => 'attempt.md',
            'source_blob_sha' => str_repeat('1', 40),
            'source_commit_sha' => str_repeat('2', 40),
            'source_imported_at' => '2020-01-01 00:00:00',
            'source_body_hash' => str_repeat('3', 64),
        ];

        $created = $this->postJson('/api/documentations', [
            'type' => $this->scope->id,
            'title' => 'srcmark-created',
            ...$attempt,
        ])->assertCreated();
        $this->assertNoSourceColumns($created, 'POST /api/documentations');
        $createdRow = Documentation::findOrFail($created->json('data.id'));
        foreach (array_keys($attempt) as $column) {
            $this->assertNull($createdRow->{$column}, "新增時不該寫入 {$column}");
        }

        $updated = $this->putJson("/api/documentations/{$this->documentation->id}", [
            'type' => $this->scope->id,
            'title' => self::TITLE,
            ...$attempt,
        ])->assertOk();
        $this->assertNoSourceColumns($updated, 'PUT /api/documentations/{documentation}');
        $updatedRow = $this->documentation->fresh();
        foreach (self::SOURCE_VALUES as $column => $value) {
            $this->assertSame($value, $updatedRow->{$column}, "更新時不該改到 {$column}");
        }
    }

    /**
     * `api/` 底下每條 GET 路由（key 是路由的 uri）要打的網址。
     *
     * @return array<string, string>
     */
    private function getUrls(): array
    {
        $documentationNode = 'documentation-'.$this->documentation->id;
        $implementationNode = 'implementation-'.$this->implementation->id;

        return [
            'api/user' => '/api/user',
            'api/scopes' => '/api/scopes',
            'api/scopes/create' => '/api/scopes/create',
            'api/scopes/{scope}' => "/api/scopes/{$this->scope->id}",
            'api/relations' => '/api/relations',
            'api/relations/create' => '/api/relations/create',
            'api/relations/{relation}' => "/api/relations/{$this->relation->id}",
            'api/relations/{relation}/edges' => "/api/relations/{$this->relation->id}/edges?per_page=100",
            'api/documentations' => '/api/documentations',
            'api/documentations/{documentation}' => "/api/documentations/{$this->documentation->id}",
            'api/techniques' => '/api/techniques',
            'api/techniques/{technique}' => "/api/techniques/{$this->technique->id}",
            'api/implementations' => '/api/implementations',
            'api/implementations/{implementation}' => "/api/implementations/{$this->implementation->id}",
            'api/graph' => '/api/graph',
            'api/graph/path' => "/api/graph/path?start={$documentationNode}&end={$implementationNode}",
        ];
    }

    private function assertNoSourceColumns(TestResponse $response, string $where): void
    {
        $leakedKeys = array_values(array_intersect(array_keys(self::SOURCE_VALUES), $this->allKeys($response->json() ?? [])));
        $this->assertSame([], $leakedKeys, "{$where} 帶出了來源欄位");

        $body = $response->getContent();
        foreach (self::SOURCE_VALUES as $column => $value) {
            $this->assertStringNotContainsString($value, $body, "{$where} 帶出了 {$column} 的值");
        }
    }

    /**
     * JSON 裡每一層的 key。
     *
     * @param  array<mixed>  $json
     * @return list<string>
     */
    private function allKeys(array $json): array
    {
        $keys = [];
        foreach ($json as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                array_push($keys, ...$this->allKeys($value));
            }
        }

        return $keys;
    }
}
