<?php

namespace Tests\Feature;

use App\Services\GitService;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 取 repo 時順便讀套件清單檔（package.json、composer.json、requirements.txt）。
 * 找清單檔只看根目錄與第一層子資料夾；任何一步失敗都只少一份清單，不中斷其他 repo。
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

    #[TestDox('monorepo 讀根目錄加第一層子資料夾的清單檔，更深的不讀')]
    public function test_it_reads_one_level_of_subdirectories_but_no_deeper(): void
    {
        $this->fakeRepo(
            $this->tree(['frontend/package.json', 'backend/composer.json', 'packages/ui/package.json', 'a/b/c/requirements.txt']),
            [
                'frontend/package.json' => json_encode(['dependencies' => ['vue' => '^3']]),
                'backend/composer.json' => json_encode(['require' => ['laravel/framework' => '^13']]),
            ],
        );

        $repo = (new GitService)->get_repos()->first();

        $this->assertEquals(['npm' => ['vue'], 'composer' => ['laravel/framework']], $repo['packages']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'packages/ui') || str_contains($request->url(), 'a/b/c'));
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
