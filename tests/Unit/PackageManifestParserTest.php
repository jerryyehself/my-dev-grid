<?php

namespace Tests\Unit;

use App\Services\PackageManifestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 套件清單檔的格式解析。只驗證「讀出哪些名稱」，哪些名稱算技術是 PackageTechniqueMapTest 的事。
 */
class PackageManifestParserTest extends TestCase
{
    #[TestDox('package.json 讀出 dependencies、devDependencies、optionalDependencies 的名稱')]
    public function test_npm_reads_all_dependency_sections(): void
    {
        $content = json_encode([
            'name' => 'demo',
            'dependencies' => ['vue' => '^3.5.0', '@vueuse/core' => '^10'],
            'devDependencies' => ['eslint' => '^9', 'Vite' => '^6'],
            'optionalDependencies' => ['fsevents' => '*'],
            'peerDependencies' => ['react' => '*'],
        ]);

        $this->assertEqualsCanonicalizing(
            ['vue', '@vueuse/core', 'eslint', 'vite', 'fsevents'],
            PackageManifestParser::parse('npm', $content),
        );
    }

    #[TestDox('composer.json 讀出 require 與 require-dev，排除 php 與 ext-* 平台需求')]
    public function test_composer_reads_require_sections_without_platform_packages(): void
    {
        $content = json_encode([
            'require' => ['php' => '^8.4', 'ext-json' => '*', 'lib-curl' => '*', 'laravel/framework' => '^13.0'],
            'require-dev' => ['phpunit/phpunit' => '^12'],
        ]);

        $this->assertEqualsCanonicalizing(
            ['laravel/framework', 'phpunit/phpunit'],
            PackageManifestParser::parse('composer', $content),
        );
    }

    #[TestDox('requirements.txt 讀出各種寫法的套件名稱')]
    public function test_pip_reads_requirement_lines(): void
    {
        $content = <<<'TXT'
        # 註解行
        Django==5.0.1
        pandas>=2.0,<3
        requests[security]~=2.31
        Beautiful_Soup4 ; python_version < "3.12"
        ruamel.yaml   # 行尾註解
        mypkg @ https://example.com/mypkg.whl
        numpy

        TXT;

        $this->assertSame(
            ['django', 'pandas', 'requests', 'beautiful-soup4', 'ruamel-yaml', 'mypkg', 'numpy'],
            PackageManifestParser::parse('pip', $content),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function nonPackageRequirementLines(): array
    {
        return [
            'include another file' => ['-r base.txt'],
            'editable install' => ['-e git+https://example.com/x.git#egg=x'],
            'index option' => ['--index-url https://example.com/simple'],
            'bare url' => ['https://example.com/x.whl'],
            'vcs url' => ['git+https://example.com/x.git'],
            'local path' => ['./vendor/x'],
            'windows path' => ['C:\\libs\\x.whl'],
            'blank' => ['   '],
            'comment only' => ['# django'],
        ];
    }

    #[DataProvider('nonPackageRequirementLines')]
    #[TestDox('requirements.txt 的非套件行不產生名稱')]
    public function test_pip_skips_lines_without_a_package_name(string $line): void
    {
        $this->assertSame([], PackageManifestParser::parse('pip', $line));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unreadableContent(): array
    {
        return [
            'npm invalid json' => ['npm', '{ not json'],
            'npm empty' => ['npm', ''],
            'npm json scalar' => ['npm', '"vue"'],
            'npm sections are not objects' => ['npm', '{"dependencies": "vue", "devDependencies": null}'],
            'composer invalid json' => ['composer', '<<<<<<< HEAD'],
            'composer no sections' => ['composer', '{"name": "a/b"}'],
            'pip empty' => ['pip', ''],
            'unknown ecosystem' => ['cargo', '[dependencies]'],
        ];
    }

    #[DataProvider('unreadableContent')]
    #[TestDox('讀不懂的內容回空陣列，不丟例外')]
    public function test_unreadable_content_returns_nothing(string $ecosystem, string $content): void
    {
        $this->assertSame([], PackageManifestParser::parse($ecosystem, $content));
    }

    #[TestDox('只認 package.json、composer.json、requirements.txt 三種檔名')]
    public function test_ecosystem_is_decided_by_file_name(): void
    {
        $this->assertSame('npm', PackageManifestParser::ecosystemFor('package.json'));
        $this->assertSame('npm', PackageManifestParser::ecosystemFor('frontend/package.json'));
        $this->assertSame('composer', PackageManifestParser::ecosystemFor('composer.json'));
        $this->assertSame('pip', PackageManifestParser::ecosystemFor('api/requirements.txt'));

        // 名稱相近但不是清單檔
        $this->assertNull(PackageManifestParser::ecosystemFor('package-lock.json'));
        $this->assertNull(PackageManifestParser::ecosystemFor('composer.lock'));
        $this->assertNull(PackageManifestParser::ecosystemFor('requirements-dev.txt'));
        $this->assertNull(PackageManifestParser::ecosystemFor('my-package.json'));
        $this->assertNull(PackageManifestParser::ecosystemFor('README.md'));
    }
}
