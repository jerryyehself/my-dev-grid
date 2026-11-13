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
            'near miss of a listed npm name' => [['npm' => ['vue-router', 'vuex', 'vue-tsc', '@vue/test-utils', 'react-dom']]],
            'near miss of a listed composer name' => [['composer' => ['laravel/framework-extra', 'laravel/tinker', 'acme/laravel/framework']]],
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
