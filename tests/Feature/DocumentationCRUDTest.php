<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class DocumentationCRUDTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('登入後可新增文件')]
    public function test_create_documentation()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => 'Laravel Docs',
            'url' => 'https://laravel.com/docs',
            'note' => 'unit test note',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['title' => 'Laravel Docs']);

        $this->assertDatabaseHas('documentations', ['title' => 'Laravel Docs', 'type' => $scope->id]);
    }

    #[TestDox('新增文件時 scope 不存在回 422')]
    public function test_create_documentation_rejects_unknown_scope()
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/documentations', [
            'type' => 99999,
            'title' => 'Laravel Docs',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    #[TestDox('新增文件未登入回 401')]
    public function test_create_documentation_rejects_unauthenticated_request()
    {
        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => 'Laravel Docs',
        ]);

        $response->assertUnauthorized();
    }

    #[TestDox('可檢視單一文件')]
    public function test_view_documentation()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->getJson("/api/documentations/{$documentation->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $documentation->id]);
    }

    #[TestDox('登入後可更新文件')]
    public function test_update_documentation()
    {
        $this->actingAsOwner();

        $documentation = Documentation::factory()->create();

        $response = $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => 'Updated title',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Updated title']);

        $this->assertDatabaseHas('documentations', ['id' => $documentation->id, 'title' => 'Updated title']);
    }

    #[TestDox('更新文件未登入回 401')]
    public function test_update_documentation_rejects_unauthenticated_request()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => 'Updated title',
        ]);

        $response->assertUnauthorized();
    }

    #[TestDox('登入後可刪除文件（軟刪除）')]
    public function test_delete_documentation()
    {
        $this->actingAsOwner();

        $documentation = Documentation::factory()->create();

        $response = $this->deleteJson("/api/documentations/{$documentation->id}");

        $response->assertOk()
            ->assertJsonFragment(['message' => "{$documentation->title} was deleted."]);

        $this->assertSoftDeleted('documentations', ['id' => $documentation->id]);
    }

    #[TestDox('刪除文件未登入回 401')]
    public function test_delete_documentation_rejects_unauthenticated_request()
    {
        $documentation = Documentation::factory()->create();

        $response = $this->deleteJson("/api/documentations/{$documentation->id}");

        $response->assertUnauthorized();
    }

    #[TestDox('可列出所有文件')]
    public function test_list_all_documentations()
    {
        Documentation::factory()->create(['title' => 'Vue Docs']);

        $response = $this->getJson('/api/documentations');

        $response->assertOk()
            ->assertJsonFragment(['title' => 'Vue Docs']);
    }

    // --- 內文欄位 body（D-46）--------------------------------------------

    #[TestDox('新增文件時原樣儲存 Markdown 內文')]
    public function test_create_documentation_stores_markdown_body()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();
        $markdown = "## 標題\n\n這是**內文**，有 `code` 跟 [連結](https://example.com)。";

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => '一篇文章',
            'body' => $markdown,
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['body' => $markdown]);

        // 原樣存進去:存的是 Markdown 原文,後端不做任何轉換或跳脫,
        // 渲染是前端走 AST 的事（D-47）
        $this->assertDatabaseHas('documentations', ['title' => '一篇文章', 'body' => $markdown]);
    }

    #[TestDox('內文欄位為選填')]
    public function test_body_is_optional()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => '外部官方文件',
            'url' => 'https://laravel.com/docs',
        ]);

        // 現有的 Documentation 幾乎都是 sourcesite（外部官方文件）,本來就沒有內文,
        // 不該因為多了這一欄就變成必填
        $response->assertCreated()
            ->assertJsonFragment(['body' => null]);
    }

    #[TestDox('更新文件時可取代內文')]
    public function test_update_documentation_replaces_body()
    {
        $this->actingAsOwner();

        $documentation = Documentation::factory()->withBody('舊的內文')->create();

        $response = $this->putJson("/api/documentations/{$documentation->id}", [
            'type' => $documentation->type,
            'title' => $documentation->title,
            'body' => '新的內文',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['body' => '新的內文']);

        $this->assertDatabaseHas('documentations', ['id' => $documentation->id, 'body' => '新的內文']);
    }

    #[TestDox('很長的內文也能完整儲存')]
    public function test_body_survives_a_long_document()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();
        // Postgres 的 text 沒有長度上限,超過約 2KB 會自動走 TOAST 外存加壓縮。
        // 這個案例是要確認沒有人在路上偷偷加了長度限制（欄位型別、驗證規則都算）
        $markdown = str_repeat("## 段落\n\n這是一段夠長的內文。\n\n", 500);

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => '很長的一篇',
            'body' => $markdown,
        ]);

        $response->assertCreated();

        $this->assertSame($markdown, Documentation::where('title', '很長的一篇')->value('body'));
    }

    #[TestDox('內文不會被去除前後空白')]
    public function test_body_is_not_trimmed()
    {
        $this->actingAsOwner();

        $scope = Scope::factory()->create();
        // 開頭的四個空白在 Markdown 裡是「程式碼區塊」,被 trim 掉等於默默改掉文件的意思。
        // Laravel 預設的 TrimStrings 會這麼做,bootstrap/app.php 把 body 排除掉了——
        // 這個案例就是在守那條排除設定,免得之後有人把它拿掉
        $markdown = "    echo 'this is a code block';\n\n一般段落\n\n";

        $response = $this->postJson('/api/documentations', [
            'type' => $scope->id,
            'title' => '前後空白要保留',
            'body' => $markdown,
        ]);

        $response->assertCreated();

        $this->assertSame($markdown, Documentation::where('title', '前後空白要保留')->value('body'));
    }

    #[TestDox('檢視文件時會回傳內文')]
    public function test_body_is_returned_by_show()
    {
        $documentation = Documentation::factory()->withBody('# 內文')->create();

        $response = $this->getJson("/api/documentations/{$documentation->id}");

        $response->assertOk()
            ->assertJsonFragment(['body' => '# 內文']);
    }
}
