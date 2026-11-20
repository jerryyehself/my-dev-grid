<?php

namespace Tests\Feature;

use App\Services\GitService;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 取 repo 時順便讀套件清單檔（package.json、composer.json、requirements.txt）。
 * 找清單檔看根目錄、第一層子資料夾，以及 apps/、packages/ 底下的子專案；任何一步失敗都只少一份清單，不中斷其他 repo。
 */
class GitServicePackagesTest extends TestCase
{
    private const TREE = 'https://api.github.com/repos/acme/demo/git/trees/HEAD*';

    private const CONTENTS = 'https://api.github.com/repos/acme/demo/contents/';

    protected function setUp(): void
    {
        parent::setUp();

        // 沒列在 fake 裡的請求要直接失敗，不要真的連出去
        Http::preventStrayRequests();
    }

    /** @param  list<string>  $paths */
    private function tree(array $paths): array
    {
        return ['tree' => array_map(fn (string $path) => ['path' => $path, 'type' => str_contains(basename($path), '.') ? 'blob' : 'tree'], $paths)];
    }

    /** @param  array<string, mixed>  $extra 額外的 Http::fake 項目 */
    private function fakeRepo(array $tree, array $files, array $extra = []): void
    {
        $fakes = [
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([[
                    'id' => 1,
                    'private' => false,
                    'name' => 'demo',
                    'html_url' => 'x',
                    'languages_url' => 'https://api.github.com/repos/acme/demo/languages',
                    'topics' => [],
                    'owner' => ['login' => 'acme'],
                ]])
                ->push([]),
            'https://api.github.com/repos/acme/demo/languages' => Http::response(['PHP' => 1]),
            self::TREE => Http::response($tree),
        ];

        foreach ($files as $path => $content) {
            $fakes[self::CONTENTS.$path] = Http::response($content);
        }

        Http::fake($extra + $fakes);
    }

    #[TestDox('讀根目錄的三種清單檔，名稱依生態系分開')]
    public function test_it_reads_root_manifests_of_each_ecosystem(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json', 'composer.json', 'requirements.txt', 'README.md', 'package-lock.json', 'src/app.js']),
            [
                'package.json' => json_encode(['dependencies' => ['vue' => '^3'], 'devDependencies' => ['eslint' => '^9']]),
                'composer.json' => json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^13']]),
                'requirements.txt' => "django==5.0\n",
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEquals([
            'npm' => ['vue', 'eslint'],
            'composer' => ['laravel/framework'],
            'pip' => ['django'],
        ], $repo['packages']);
    }

    #[TestDox('要求原始內容的 Accept 標頭只用在讀檔，不影響之後的請求')]
    public function test_raw_accept_header_is_only_sent_when_reading_files(): void
    {
        $repo = fn (int $id, string $name) => [
            'id' => $id, 'private' => false, 'name' => $name, 'html_url' => 'x', 'topics' => [],
            'languages_url' => "https://api.github.com/repos/acme/{$name}/languages",
            'owner' => ['login' => 'acme'],
        ];

        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()->push([$repo(1, 'demo'), $repo(2, 'other')])->push([]),
            'https://api.github.com/repos/acme/*/languages' => Http::response([]),
            'https://api.github.com/repos/acme/*/git/trees/*' => Http::response($this->tree(['package.json'])),
            'https://api.github.com/repos/acme/*/contents/package.json' => Http::response('{}'),
        ]);

        (new GitService)->get_repos();

        $raw = 'application/vnd.github.raw+json';
        $accepts = Http::recorded()->mapWithKeys(fn (array $pair) => [$pair[0]->url() => $pair[0]->header('Accept')[0] ?? null]);

        // 讀檔的請求要拿原始內容，其餘（包含第二個 repo 的列檔請求）維持預設
        $this->assertSame($raw, $accepts['https://api.github.com/repos/acme/demo/contents/package.json']);
        $this->assertSame($raw, $accepts['https://api.github.com/repos/acme/other/contents/package.json']);
        $this->assertNotSame($raw, $accepts['https://api.github.com/repos/acme/other/git/trees/HEAD?recursive=1']);
        $this->assertNotSame($raw, $accepts['https://api.github.com/repos/acme/other/languages']);
    }

    #[TestDox('monorepo 讀根目錄加第一層子資料夾的清單檔，其他更深的不讀')]
    public function test_it_reads_one_level_of_subdirectories_but_no_deeper(): void
    {
        $this->fakeRepo(
            $this->tree(['frontend/package.json', 'backend/composer.json', 'a/b/package.json', 'a/b/c/requirements.txt', 'docs/guide/site/package.json']),
            [
                'frontend/package.json' => json_encode(['dependencies' => ['vue' => '^3']]),
                'backend/composer.json' => json_encode(['require' => ['laravel/framework' => '^13']]),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEquals(['npm' => ['vue'], 'composer' => ['laravel/framework']], $repo['packages']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'a/b') || str_contains($request->url(), 'docs/guide'));
    }

    #[TestDox('apps/、packages/ 底下的子專案（第三層）也讀，laravel-migration-visualizer 這種 monorepo 三份都讀得到')]
    public function test_it_reads_workspace_packages_under_apps_and_packages(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json', 'apps/web/package.json', 'packages/migration-core/package.json', 'apps/api/composer.json', 'README.md']),
            [
                'package.json' => json_encode(['devDependencies' => ['typescript' => '^5']]),
                'apps/web/package.json' => json_encode(['dependencies' => ['vue' => '^3.5']]),
                'packages/migration-core/package.json' => json_encode(['dependencies' => ['php-parser' => '^3.2']]),
                'apps/api/composer.json' => json_encode(['require' => ['laravel/framework' => '^13']]),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEqualsCanonicalizing(['typescript', 'vue', 'php-parser'], $repo['packages']['npm']);
        $this->assertEquals(['laravel/framework'], $repo['packages']['composer']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/contents/packages/migration-core/package.json'));
    }

    /** @return array<string, array{0: string}> 不該被讀的第三層（以上）路徑 */
    public static function pathsThatStayUnread(): array
    {
        return [
            'third level under a normal directory' => ['lib/web/package.json'],
            'name only starts like a container' => ['packages-extra/web/package.json'],
            'name ends like a container' => ['my-apps/web/package.json'],
            'fourth level under apps' => ['apps/web/src/package.json'],
            'fourth level under packages' => ['packages/ui/fixtures/package.json'],
            'skipped directory inside apps' => ['apps/node_modules/package.json'],
            'tests inside packages' => ['packages/tests/package.json'],
            'examples inside apps' => ['apps/examples/composer.json'],
            'hidden directory inside packages' => ['packages/.cache/package.json'],
            'vendor inside packages' => ['packages/vendor/composer.json'],
            'not a manifest' => ['apps/web/package-lock.json'],
        ];
    }

    #[DataProvider('pathsThatStayUnread')]
    #[TestDox('apps/、packages/ 以外的第三層、更深的層級、跳過的資料夾都不讀')]
    public function test_it_does_not_read_other_deep_or_skipped_paths(string $path): void
    {
        $this->fakeRepo($this->tree([$path]), []);

        $repo = (new GitService)->get_repos()->first();

        $this->assertSame([], $repo['packages']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/contents/'));
    }

    #[TestDox('第三層的清單檔也算進每個 repo 最多 10 份，排在根目錄與第一層之後')]
    public function test_deeper_manifests_count_toward_the_cap_and_come_last(): void
    {
        $paths = ['package.json', 'web/package.json'];
        $files = [
            'package.json' => json_encode(['dependencies' => ['vue' => '^3']]),
            'web/package.json' => json_encode(['dependencies' => ['pinia' => '^2']]),
        ];

        foreach (range(1, 12) as $i) {
            $paths[] = sprintf('packages/p%02d/package.json', $i);
            $files[sprintf('packages/p%02d/package.json', $i)] = json_encode(['dependencies' => ['react' => '^19']]);
        }

        $this->fakeRepo($this->tree($paths), $files);

        $repo = (new GitService)->get_repos()->first();

        $this->assertEqualsCanonicalizing(['vue', 'pinia', 'react'], $repo['packages']['npm']);
        Http::assertSentCount(2 + 1 + 1 + 10);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'packages/p09') || str_contains($request->url(), 'packages/p12'));
    }

    #[TestDox('tree 回應被 GitHub 截斷時記一筆 warning（含 repo 名稱），用已拿到的部分繼續，不中斷')]
    public function test_a_truncated_tree_logs_a_warning_and_keeps_going(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json']) + ['truncated' => true],
            ['package.json' => json_encode(['dependencies' => ['vue' => '^3']])],
        );
        $logs = [];
        Log::listen(function (MessageLogged $message) use (&$logs) {
            $logs[] = $message;
        });

        $repo = (new GitService)->get_repos()->first();

        $warnings = array_values(array_filter($logs, fn (MessageLogged $log) => $log->level === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertSame('acme/demo', $warnings[0]->context['repo']);
        $this->assertStringContainsString('truncated', $warnings[0]->message);
        // 同步沒有中斷：其餘欄位照常，拿到的清單檔照讀
        $this->assertSame(['vue'], $repo['packages']['npm']);
        $this->assertSame('demo', $repo['title']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function untruncatedTreeResponses(): array
    {
        return [
            'truncated false' => [['truncated' => false]],
            'truncated missing' => [[]],
            // 接近但不是布林 true 的值不當成截斷
            'truncated as string' => [['truncated' => 'false']],
            'truncated null' => [['truncated' => null]],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    #[DataProvider('untruncatedTreeResponses')]
    #[TestDox('tree 沒有被截斷時不記 warning')]
    public function test_an_untruncated_tree_logs_no_warning(array $extra): void
    {
        $this->fakeRepo($this->tree(['package.json']) + $extra, ['package.json' => '{}']);
        $logs = [];
        Log::listen(function (MessageLogged $message) use (&$logs) {
            $logs[] = $message;
        });

        (new GitService)->get_repos();

        $this->assertSame([], array_filter($logs, fn (MessageLogged $log) => $log->level === 'warning'));
    }

    #[TestDox('只記 Vue、Nuxt、Bootstrap 的版本限制到 package_versions，其他套件不記')]
    public function test_it_records_version_constraints_only_for_versioned_packages(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json', 'web/package.json']),
            [
                'package.json' => "\xEF\xBB\xBF".json_encode(['dependencies' => ['vue' => '^3.5.0', 'pinia' => '^2'], 'devDependencies' => ['eslint' => '^9']]),
                'web/package.json' => json_encode(['dependencies' => ['vue' => '^2.6', 'bootstrap' => '~5.3']]),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        // 根目錄的先讀，同名套件以先讀到的為準
        $this->assertSame(['npm' => ['vue' => '^3.5.0', 'bootstrap' => '~5.3']], $repo['package_versions']);
    }

    #[TestDox('沒有任何版本限制可記時 package_versions 是空陣列')]
    public function test_package_versions_is_empty_without_versioned_packages(): void
    {
        $this->fakeRepo($this->tree(['package.json']), ['package.json' => json_encode(['dependencies' => ['pinia' => '^2']])]);

        $this->assertSame([], (new GitService)->get_repos()->first()['package_versions']);
    }

    #[TestDox('根目錄與子資料夾的同類清單檔，名稱合併且不重複')]
    public function test_it_merges_the_same_ecosystem_across_manifests(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json', 'web/package.json']),
            [
                'package.json' => json_encode(['dependencies' => ['vue' => '^3', 'pinia' => '^2']]),
                'web/package.json' => json_encode(['dependencies' => ['vue' => '^3', 'vite' => '^6']]),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEqualsCanonicalizing(['vue', 'pinia', 'vite'], $repo['packages']['npm']);
    }

    #[TestDox('範例、測試資料、第三方套件與隱藏資料夾裡的清單檔不讀')]
    public function test_it_skips_directories_that_do_not_describe_the_repo(): void
    {
        $skipped = ['node_modules/package.json', 'vendor/composer.json', 'tests/package.json', 'test/package.json', 'fixtures/package.json',
            'examples/package.json', 'example/package.json', '.github/package.json', '.devcontainer/composer.json', 'Vendor/composer.json'];

        $this->fakeRepo($this->tree($skipped), []);

        $repo = (new GitService)->get_repos()->first();

        $this->assertSame([], $repo['packages']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/contents/'));
    }

    #[TestDox('清單檔太多時最多讀 10 份，根目錄的優先')]
    public function test_it_caps_the_number_of_manifests_and_prefers_the_root(): void
    {
        $paths = ['package.json'];
        $files = ['package.json' => json_encode(['dependencies' => ['vue' => '^3']])];

        foreach (range(1, 15) as $i) {
            $paths[] = sprintf('app%02d/package.json', $i);
            $files[sprintf('app%02d/package.json', $i)] = json_encode(['dependencies' => ['react' => '^19']]);
        }

        $this->fakeRepo($this->tree($paths), $files);

        $repo = (new GitService)->get_repos()->first();

        $this->assertContains('vue', $repo['packages']['npm']);
        Http::assertSentCount(2 + 1 + 1 + 10); // user/repos 兩頁、languages、tree 各一次，加清單檔 10 份
    }

    #[TestDox('找不到任何清單檔時 packages 是空陣列，也不讀檔')]
    public function test_it_returns_empty_packages_when_there_are_no_manifests(): void
    {
        $this->fakeRepo($this->tree(['README.md', 'src/main.py']), []);

        $repo = (new GitService)->get_repos()->first();

        $this->assertSame([], $repo['packages']);
    }

    /** @return array<string, array{0: Closure(): mixed}> */
    public static function failingFileListings(): array
    {
        return [
            'empty repo (409)' => [fn () => Http::response(['message' => 'Git Repository is empty.'], 409)],
            'rate limited (403)' => [fn () => Http::response(['message' => 'rate limit'], 403)],
            'server error (500)' => [fn () => Http::response(['message' => 'boom'], 500)],
            'connection failure' => [fn () => throw new ConnectionException('timeout')],
            'tree key missing' => [fn () => Http::response(['sha' => 'abc'])],
        ];
    }

    #[DataProvider('failingFileListings')]
    #[TestDox('列檔案失敗時 packages 是空陣列，其餘欄位照常')]
    public function test_it_survives_a_failing_file_listing(Closure $listing): void
    {
        $this->fakeRepo([], [], [self::TREE => $listing]);

        $repo = (new GitService)->get_repos()->first();

        $this->assertSame([], $repo['packages']);
        $this->assertSame(['PHP'], $repo['languages']);
        $this->assertSame('demo', $repo['title']);
    }

    #[TestDox('其中一份清單檔讀不到時，其他清單檔照常讀')]
    public function test_it_keeps_going_when_one_manifest_cannot_be_read(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json', 'composer.json', 'requirements.txt']),
            ['composer.json' => json_encode(['require' => ['laravel/framework' => '^13']])],
            [
                self::CONTENTS.'package.json' => Http::response(['message' => 'Not Found'], 404),
                self::CONTENTS.'requirements.txt' => fn () => throw new ConnectionException('timeout'),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEquals(['composer' => ['laravel/framework']], $repo['packages']);
    }

    #[TestDox('清單檔內容壞掉時，該份當作沒有套件')]
    public function test_it_treats_a_broken_manifest_as_empty(): void
    {
        $this->fakeRepo(
            $this->tree(['package.json', 'composer.json']),
            [
                'package.json' => '{ "dependencies": { "vue": ',
                'composer.json' => json_encode(['require' => ['laravel/framework' => '^13']]),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEquals(['composer' => ['laravel/framework']], $repo['packages']);
    }

    #[TestDox('子資料夾名稱含特殊字元時，讀檔網址有編碼')]
    public function test_it_url_encodes_manifest_paths(): void
    {
        $this->fakeRepo(
            $this->tree(['my app/package.json']),
            [],
            [self::CONTENTS.'my%20app/package.json' => Http::response(json_encode(['dependencies' => ['vue' => '^3']]))],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEquals(['npm' => ['vue']], $repo['packages']);
    }

    #[TestDox('沒有擁有者帳號的資料不去讀清單檔')]
    public function test_it_does_not_read_manifests_without_an_owner(): void
    {
        Http::fake([
            'https://api.github.com/user/repos*' => Http::sequence()
                ->push([['id' => 1, 'private' => false, 'name' => 'demo', 'html_url' => 'x', 'topics' => []]])
                ->push([]),
        ]);

        $repo = (new GitService)->get_repos()->first();

        $this->assertSame([], $repo['packages']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/git/trees/'));
    }
}
