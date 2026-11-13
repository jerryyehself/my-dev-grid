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
            $map = config("package_technologies.{$ecosystem}");

            if (! is_array($map) || ! is_array($names)) {
                continue;
            }

            foreach ($names as $name) {
                if (! is_string($name)) {
                    continue;
                }

                $technique = $map[mb_strtolower($name)] ?? null;

                if ($technique !== null) {
                    $found[mb_strtolower($technique[0]).'|'.$technique[1]] = $technique;
                }
            }
        }

        return array_values($found);
    }
}
