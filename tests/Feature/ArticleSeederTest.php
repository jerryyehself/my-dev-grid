<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Scope;
use Database\Seeders\ArticleSeeder;
use Database\Seeders\ScopeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ArticleSeeder：把 4 份報告填成已發布文章，要冪等（重跑不重複）、公開 API 看得到。
 */
class ArticleSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TITLES = [
        '前端建置工具與部署概念問答整理',
        '首頁知識視覺化設計決策記錄',
        '節省 Token／降變異度機制盤點',
        'my-dev-grid-front 部署到 Cloudflare 的完整流程',
    ];

    public function test_running_twice_yields_exactly_four_published_articles()
    {
        $this->seed(ScopeSeeder::class);

        $this->seed(ArticleSeeder::class);
        $this->seed(ArticleSeeder::class);

        $this->assertSame(4, Documentation::count());
        $this->assertSame(4, Documentation::where('status', Documentation::STATUS_PUBLISHED)->count());

        $postId = Scope::where('name', 'post')->value('id');
        $this->assertSame(4, Documentation::where('type', $postId)->count());
    }

    public function test_articles_are_visible_via_the_public_api_without_auth()
    {
        $this->seed(ScopeSeeder::class);
        $this->seed(ArticleSeeder::class);
        $this->seed(ArticleSeeder::class);

        $response = $this->getJson('/api/documentations')->assertOk();

        $this->assertCount(4, $response->json('data'));

        foreach (self::TITLES as $title) {
            $response->assertJsonFragment(['title' => $title, 'status' => 1]);
        }

        foreach ($response->json('data') as $article) {
            $this->assertSame('post', $article['scope']['name']);
            $this->assertNotEmpty($article['body']);
            // 標題是獨立欄位，body 不該再以重複的 H1 開頭
            $this->assertStringStartsNotWith('# ', $article['body']);
        }
    }

    public function test_rerun_overwrites_body_instead_of_duplicating()
    {
        $this->seed(ScopeSeeder::class);
        $this->seed(ArticleSeeder::class);

        Documentation::where('title', self::TITLES[0])->update(['body' => '被手動改過']);

        $this->seed(ArticleSeeder::class);

        $this->assertSame(4, Documentation::count());
        $this->assertNotSame('被手動改過', Documentation::where('title', self::TITLES[0])->value('body'));
    }

    public function test_soft_deleted_article_is_not_resurrected()
    {
        $this->seed(ScopeSeeder::class);
        $this->seed(ArticleSeeder::class);

        Documentation::where('title', self::TITLES[1])->first()->delete();

        $this->seed(ArticleSeeder::class);

        $this->assertSame(3, Documentation::count());
        $this->assertSame(4, Documentation::withTrashed()->count());
    }

    public function test_it_throws_a_clear_error_when_the_post_scope_is_missing()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ScopeSeeder');

        $this->seed(ArticleSeeder::class);
    }

    /**
     * 這些內容會公開。守住隱私檢查拿掉的東西，之後重新複製 fixture 時漏掉會直接紅燈。
     */
    public function test_seeded_bodies_contain_no_sensitive_strings()
    {
        $this->seed(ScopeSeeder::class);
        $this->seed(ArticleSeeder::class);

        foreach (Documentation::all() as $article) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $article->body, "{$article->title}: email");
            $this->assertDoesNotMatchRegularExpression('/\b(session_|cse_|trig_)[A-Za-z0-9]+/', $article->body, "{$article->title}: session/trigger id");
            $this->assertDoesNotMatchRegularExpression('/\b[0-9a-f]{32}\b/', $article->body, "{$article->title}: 32 位十六進位（帳號 id／token）");
            $this->assertStringNotContainsString('jerry40522', $article->body);
            $this->assertStringNotContainsString('jerry-dev', $article->body);
            $this->assertStringNotContainsString('求職', $article->body);
            $this->assertStringNotContainsString('履歷', $article->body);
            $this->assertStringNotContainsString('薪', $article->body);
        }
    }
}
