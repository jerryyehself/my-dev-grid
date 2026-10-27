<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 新增/編輯表單的欄位定義端點:欄位、型別與選項全部由後端這兩支決定。
 *
 * 這支測試存在的理由很具體:PR #55 把 routes/web.php 的 Route::resources 當成死碼
 * 移除,而當時的後台(Triple,已移除)呼叫的是**不帶 /api 前綴**的 `/scopes/create`,
 * 測試也沒有任何一支覆蓋它。結果是表單整個壞掉,而 CI 全綠。
 */
class FormSchemaEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 「create」不能被 apiResource 的 {scope} 佔位符吃掉——註冊順序錯了就會變成
     * 拿字串 "create" 去查資料庫,回 404。
     */
    #[TestDox('scope 表單結構端點回傳欄位定義')]
    public function test_scope_form_schema_endpoint_returns_field_definitions()
    {
        $this->seed();

        $response = $this->getJson('/api/scopes/create')->assertOk();

        // 表單送出的是父 Scope 的 id,所以欄位名是 parent_class,不是 class_number
        // ——後者曾經是這個欄位的名字,但它從來不是分類號本身。
        $response->assertJsonStructure([
            'name' => ['label', 'required', 'type'],
            'parent_class' => ['label', 'required', 'type', 'options'],
            'call_number' => ['label', 'required', 'type'],
            'comment' => ['label', 'required', 'type'],
        ]);

        $this->assertArrayNotHasKey(
            'class_number',
            $response->json(),
            'class_number 是由後端從 parent_class 推導的,不該出現在表單欄位裡。'
        );

        // 選項只能是頂層 Scope（parent_class 為 null 的那幾筆）。
        $options = $response->json('parent_class.options');
        $this->assertNotEmpty($options, '應該要有可選的父類。');

        // label 沿用資料表欄位註解的用詞,不要另外發明同義詞——這支 create() 的
        // 其他 label（類號/子類號/範圍說明/註釋）全部都是這樣對應的。
        $this->assertSame('父類', $response->json('parent_class.label'));
    }

    #[TestDox('relation 表單結構端點可正常存取')]
    public function test_relation_form_schema_endpoint_is_reachable()
    {
        $this->seed();

        $this->getJson('/api/relations/create')->assertOk();
    }
}
