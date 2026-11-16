<?php

namespace Tests\Feature;

use App\Services\PackageTechniqueMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 「套件 → 技術」的白名單篩選（config/package_technologies.php）：不在表裡的一律不產生技術。
 */
class PackageTechniqueMapTest extends TestCase
{
    #[TestDox('對照表裡的套件變成技術，連同 scope')]
    public function test_mapped_packages_become_techniques(): void
    {
        $techniques = PackageTechniqueMap::techniquesFor([
            'npm' => ['vue', 'typescript'],
            'composer' => ['laravel/framework'],
            'pip' => ['django'],
        ]);

        $this->assertEqualsCanonicalizing([
            ['Vue', 'framework'],
            ['TypeScript', 'language'],
            ['Laravel', 'framework'],
            ['Django', 'framework'],
        ], $techniques);
    }

    /** @return array<string, array{0: array<string, list<string>>}> */
    public static function packagesThatMustStayOffTheGraph(): array
    {
        return [
            'npm dev tools' => [['npm' => ['eslint', 'prettier', 'vitest', 'jsdom', 'oxlint', '@types/node']]],
            'composer dev tools' => [['composer' => ['phpunit/phpunit', 'laravel/pint', 'mockery/mockery', 'larastan/larastan']]],
            'unlisted but common' => [['npm' => ['lodash', 'axios'], 'pip' => ['requests']]],
            // 名稱跟已列的相近，但是另一個套件
            'near miss of a listed npm name' => [['npm' => ['vue-router-extra', 'vuex', 'vue-tsc', '@vue/test-utils', 'react-dom', 'alpine', 'next-auth', 'graphql', 'nikic/php-parser']]],
            'near miss of a listed composer name' => [['composer' => ['laravel/framework-extra', 'laravel/tinker', 'acme/laravel/framework', 'nikic/php-parser-extra', 'webonyx/graphql-php']]],
            // 已列的名稱，但放在別的生態系
            'npm name under pip' => [['pip' => ['vue', 'typescript']]],
            'composer name under npm' => [['npm' => ['laravel/framework']]],
            'unknown ecosystem' => [['cargo' => ['vue', 'django']]],
            'empty' => [[]],
            'malformed values' => [['npm' => 'vue', 'composer' => [123, null, ['vue']]]],
        ];
    }

    /** @param  array<string, mixed>  $packages */
    #[DataProvider('packagesThatMustStayOffTheGraph')]
    #[TestDox('不在對照表的套件不產生技術')]
    public function test_unmapped_packages_are_ignored(array $packages): void
    {
        $this->assertSame([], PackageTechniqueMap::techniquesFor($packages));
    }

    #[TestDox('對照時套件名稱不分大小寫')]
    public function test_package_names_are_matched_case_insensitively(): void
    {
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['Vue']]));
    }

    #[TestDox('同一個技術有多個套件時只回一筆')]
    public function test_techniques_are_deduplicated(): void
    {
        $techniques = PackageTechniqueMap::techniquesFor([
            'npm' => ['tailwindcss', '@tailwindcss/vite', 'tailwindcss'],
        ]);

        $this->assertSame([['tailwind', 'packagetool']], $techniques);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function gapFillingPackages(): array
    {
        return [
            'alpinejs' => ['npm', 'alpinejs', 'Alpine.js', 'framework'],
            'vue-router' => ['npm', 'vue-router', 'vue-router', 'packagetool'],
            'next' => ['npm', 'next', 'Next.js', 'framework'],
            'php-parser (npm)' => ['npm', 'php-parser', 'php-parser', 'packagetool'],
            'nikic/php-parser' => ['composer', 'nikic/php-parser', 'php-parser', 'packagetool'],
            'rebing/graphql-laravel' => ['composer', 'rebing/graphql-laravel', 'GraphQL', 'packagetool'],
            'nuwave/lighthouse' => ['composer', 'nuwave/lighthouse', 'GraphQL', 'packagetool'],
        ];
    }

    #[DataProvider('gapFillingPackages')]
    #[TestDox('補進對照表的套件對到指定的技術與 scope')]
    public function test_gap_filling_packages_map_to_techniques(string $ecosystem, string $package, string $title, string $scope): void
    {
        $this->assertSame([$title, $scope], PackageTechniqueMap::techniqueFor($ecosystem, $package));
        $this->assertSame([[$title, $scope]], PackageTechniqueMap::techniquesFor([$ecosystem => [$package]]));
    }

    #[TestDox('兩個 GraphQL 套件、兩個 php-parser 套件各自合成一筆技術')]
    public function test_packages_of_the_same_technique_collapse(): void
    {
        $this->assertSame(
            [['GraphQL', 'packagetool'], ['php-parser', 'packagetool']],
            PackageTechniqueMap::techniquesFor(['composer' => ['rebing/graphql-laravel', 'nuwave/lighthouse', 'nikic/php-parser'], 'npm' => ['php-parser']]),
        );
    }

    /** @return array<string, array{0: string, 1: string|null}> constraint → 主版號 */
    public static function constraints(): array
    {
        return [
            'caret' => ['^3.5.0', '3'],
            'caret with two parts' => ['^2.6', '2'],
            'tilde' => ['~5.3.2', '5'],
            'exact' => ['3.4.21', '3'],
            'bare major' => ['3', '3'],
            'x range' => ['3.x', '3'],
            'star range' => ['3.*', '3'],
            'v prefix' => ['v3.2.0', '3'],
            'greater or equal' => ['>=3.0.0 <4', '3'],
            'first of alternatives' => ['^3.0 || ^4.0', '3'],
            'composer alternatives' => ['^13.0|^12.0', '13'],
            'prerelease' => ['^3.5.0-beta.1', '3'],
            'surrounding spaces' => ['  ^3.5  ', '3'],
            'three digit major' => ['^120.1', '120'],
            // 讀不出主版號的一律不帶版本
            'any' => ['*', null],
            'x only' => ['x', null],
            'empty' => ['', null],
            'tag' => ['latest', null],
            'tag next' => ['next', null],
            'workspace' => ['workspace:*', null],
            'workspace with number' => ['workspace:^3.0.0', null],
            'git url' => ['github:vuejs/core', null],
            'git url with version' => ['git+https://github.com/vuejs/core.git#v3.5', null],
            'local path' => ['file:../vue', null],
            'npm alias' => ['npm:@vue/compat@^3.5', null],
            'dev branch' => ['dev-main', null],
            'major zero' => ['^0.12.0', null],
            'leading zero' => ['03.1', null],
            'digits glued to letters' => ['3abc', null],
            'huge number' => ['^99999999999', null],
            'tag before range' => ['latest || ^3', null],
        ];
    }

    #[DataProvider('constraints')]
    #[TestDox('從版本限制取第一個主版號，取不到回 null')]
    public function test_major_version_of_a_constraint(string $constraint, ?string $expected): void
    {
        $this->assertSame($expected, PackageTechniqueMap::majorVersion($constraint));
    }

    #[TestDox('Vue、Nuxt、Bootstrap 依套件的版本限制帶出主版號')]
    public function test_versioned_techniques_carry_the_major_version(): void
    {
        $techniques = PackageTechniqueMap::techniquesFor(
            ['npm' => ['vue', 'nuxt', 'bootstrap', 'pinia']],
            ['npm' => ['vue' => '^2.6.14', 'nuxt' => '^3.11', 'bootstrap' => '~5.3', 'pinia' => '^2.1']],
        );

        $this->assertEqualsCanonicalizing([
            ['Vue', 'framework', '2'],
            ['Nuxt', 'framework', '3'],
            ['Bootstrap', 'packagetool', '5'],
            ['Pinia', 'packagetool'],
        ], $techniques);
    }

    #[TestDox('取不到主版號、或沒給版本限制時，Vue、Nuxt、Bootstrap 不帶版本')]
    public function test_versioned_techniques_without_a_readable_version_stay_unversioned(): void
    {
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['vue']], ['npm' => ['vue' => '*']]));
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['vue']]));
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['vue']], ['npm' => []]));
        // 版本限制放在別的生態系、或別的套件底下，不能拿來用
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['vue']], ['pip' => ['vue' => '^3'], 'npm' => ['nuxt' => '^3']]));
        // 形狀不對的版本資料當作沒有
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['vue']], ['npm' => ['vue' => ['^3']]]));
        $this->assertSame([['Vue', 'framework']], PackageTechniqueMap::techniquesFor(['npm' => ['vue']], ['npm' => 'vue']));
    }

    #[TestDox('不在 Vue、Nuxt、Bootstrap 之外的技術，即使有版本限制也不帶版本')]
    public function test_other_techniques_never_get_a_version(): void
    {
        $techniques = PackageTechniqueMap::techniquesFor(
            ['npm' => ['react', 'vue-router', 'typescript', 'vuetify', '@nuxtjs/i18n'], 'composer' => ['laravel/framework']],
            ['npm' => ['react' => '^19', 'vue-router' => '^4', 'typescript' => '^5', 'vuetify' => '^3', '@nuxtjs/i18n' => '^8'], 'composer' => ['laravel/framework' => '^13']],
        );

        foreach ($techniques as $technique) {
            $this->assertCount(2, $technique, $technique[0]);
        }
    }

    #[TestDox('對照表裡每一筆都是 [名稱, 合法的 scope]，key 都是小寫')]
    public function test_every_config_entry_is_well_formed(): void
    {
        $this->assertSame(['npm', 'composer', 'pip'], array_keys(config('package_technologies')));

        foreach (config('package_technologies') as $ecosystem => $map) {
            foreach ($map as $package => $technique) {
                $this->assertSame(mb_strtolower($package), $package, "{$ecosystem}: {$package} 的 key 要小寫");
                $this->assertCount(2, $technique, "{$ecosystem}: {$package}");
                $this->assertNotSame('', $technique[0], "{$ecosystem}: {$package}");
                $this->assertContains($technique[1], ['language', 'framework', 'packagetool'], "{$ecosystem}: {$package}");
            }
        }
    }
}
