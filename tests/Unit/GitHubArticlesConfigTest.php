<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 釘住 config/services.php 的 `github_articles`：匯入文章用的 token 與 repo 沒設定時是 null。
 *
 * 部署時 repository variable 沒建，Cloud Run 拿到的是空字串而不是「沒有這個變數」，
 * 所以「沒設」與「空字串」兩種都要變成 null，讀取設定的地方才只需要判斷一種情況。
 * 直接載入設定檔、不啟動 Laravel，才能控制每一個環境變數有沒有設。
 */
class GitHubArticlesConfigTest extends TestCase
{
    // GITHUB_TOKEN 也先清掉、測完還原，才測得出 token 沒有退回去讀它。
    private const KEYS = ['GITHUB_ARTICLES_TOKEN', 'GITHUB_ARTICLES_REPO', 'GITHUB_ARTICLES_REF', 'GITHUB_TOKEN'];

    /** @var array<string, array{server: mixed, env: mixed, getenv: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
                'getenv' => getenv($key),
            ];
            $this->unsetEnv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $this->unsetEnv($key);
            if ($value['server'] !== null) {
                $_SERVER[$key] = $value['server'];
            }
            if ($value['env'] !== null) {
                $_ENV[$key] = $value['env'];
            }
            if ($value['getenv'] !== false) {
                putenv("{$key}={$value['getenv']}");
            }
        }

        parent::tearDown();
    }

    private function unsetEnv(string $key): void
    {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    private function setEnv(string $key, string $value): void
    {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $services = require __DIR__.'/../../config/services.php';

        return $services['github_articles'];
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function cases(): array
    {
        return [
            'token 沒設' => ['token', null, null],
            'token 空字串' => ['token', '', null],
            'token 有值' => ['token', 'test-token', 'test-token'],
            'repo 沒設' => ['repo', null, null],
            'repo 空字串' => ['repo', '', null],
            'repo 有值' => ['repo', 'example-owner/example-repo', 'example-owner/example-repo'],
            'ref 沒設' => ['ref', null, 'main'],
            'ref 空字串' => ['ref', '', 'main'],
            'ref 有值' => ['ref', 'release', 'release'],
        ];
    }

    #[TestDox('匯入文章的設定：沒設或空字串時用預設值')]
    #[DataProvider('cases')]
    public function test_github_articles_config_falls_back_when_unset_or_empty(string $field, ?string $envValue, ?string $expected): void
    {
        if ($envValue !== null) {
            $this->setEnv('GITHUB_ARTICLES_'.strtoupper($field), $envValue);
        }

        $this->assertSame($expected, $this->loadConfig()[$field]);
    }

    #[TestDox('匯入文章的 token 不會拿 GitService 的 GITHUB_TOKEN 來用')]
    public function test_github_articles_token_does_not_fall_back_to_github_token(): void
    {
        $this->setEnv('GITHUB_TOKEN', 'repo-sync-token');

        $this->assertNull($this->loadConfig()['token']);
    }
}
