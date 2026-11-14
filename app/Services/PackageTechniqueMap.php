<?php

namespace App\Services;

/**
 * 套件 → 技術的對照：把一個 repo 清單檔裡的套件名稱，篩成要上圖譜的技術。
 *
 * 對照表在 `config/package_technologies.php`。篩選是「白名單」：不在表裡的套件
 * （開發用工具、一般的函式庫）一律不產生技術，輸入再怪也只會少邊，不會多邊。
 */
final class PackageTechniqueMap
{
    /**
     * @param  array<string, list<string>>  $packages  生態系 → 套件名稱，形狀同 GitService 回傳的 `packages`
     * @return list<array{0: string, 1: string}> 去重後的 [技術名稱, scope]
     */
    public static function techniquesFor(array $packages): array
    {
        $found = [];

        foreach ($packages as $ecosystem => $names) {
            if (! is_array($names)) {
                continue;
            }

            foreach ($names as $name) {
                $technique = is_string($name) ? self::techniqueFor((string) $ecosystem, $name) : null;

                if ($technique !== null) {
                    $found[mb_strtolower($technique[0]).'|'.$technique[1]] = $technique;
                }
            }
        }

        return array_values($found);
    }

    /**
     * 單一個套件對到的技術；不在對照表裡（包括不認得的生態系）是 null。
     *
     * @return array{0: string, 1: string}|null [技術名稱, scope]
     */
    public static function techniqueFor(string $ecosystem, string $name): ?array
    {
        // 不用 config("….{$name}")：`chart.js` 這類名稱裡的點會被當成巢狀 key
        $map = config("package_technologies.{$ecosystem}");
        $technique = is_array($map) ? ($map[mb_strtolower($name)] ?? null) : null;

        return is_array($technique) ? $technique : null;
    }
}
