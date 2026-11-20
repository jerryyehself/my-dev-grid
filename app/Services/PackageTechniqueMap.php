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
     * 會依套件版本帶出版本節點的技術（技術名稱小寫）。只放圖上本來就有版本節點、而且版本
     * 有意義的 Vue、Nuxt、Bootstrap（見 SaveReposDataService::AUTHORITY）；其他技術的主版號
     * （React 19、Laravel 13…）常常更新、也沒有人要看，加進來會讓圖上多出一堆版本節點。
     */
    public const VERSIONED_TECHNIQUES = ['vue', 'nuxt', 'bootstrap'];

    /**
     * @param  array<string, list<string>>  $packages  生態系 → 套件名稱，形狀同 GitService 回傳的 `packages`
     * @param  array<string, array<string, string>>  $versions  生態系 → 套件名稱 → 版本限制，形狀同 GitService 回傳的 `package_versions`
     * @return list<array{0: string, 1: string}|array{0: string, 1: string, 2: string}> 去重後的 [技術名稱, scope]；
     *                                                                                  VERSIONED_TECHNIQUES 的技術讀得出主版號時多一個元素 [技術名稱, scope, 主版號]
     */
    public static function techniquesFor(array $packages, array $versions = []): array
    {
        $found = [];

        foreach ($packages as $ecosystem => $names) {
            if (! is_array($names)) {
                continue;
            }

            foreach ($names as $name) {
                $technique = is_string($name) ? self::techniqueFor((string) $ecosystem, $name) : null;

                if ($technique === null) {
                    continue;
                }

                $key = mb_strtolower($technique[0]).'|'.$technique[1];

                if (isset($found[$key])) {
                    continue;
                }

                $major = self::majorVersionOf((string) $ecosystem, $name, $technique, $versions);
                $found[$key] = $major === null ? $technique : [...$technique, $major];
            }
        }

        return array_values($found);
    }

    /**
     * 版本限制的第一個主版號，例如 `^3.5.0` → `3`、`>=2.6 <4` → `2`、`3.x` → `3`、`^3 || ^4` → `3`。
     * 讀不出來就是 null，呼叫的一邊不帶版本：`*`、`latest`、`workspace:*`、git 網址、本機路徑、
     * `npm:` 別名，只寫上限的限制（`<4`、`<=3.2`：4 是不能用的版本，不是在用的版本），以及主版號 0（0.x 沒有穩定的主版本）或超過三位數的怪值。
     */
    public static function majorVersion(string $constraint): ?string
    {
        // 開頭是 < 的只寫了上限，沒說在用哪個版本
        if (str_starts_with(ltrim($constraint), '<')) {
            return null;
        }

        return preg_match('/^[\s~^<>=v]*([1-9]\d{0,2})(?=$|[.\s|,-])/', $constraint, $match) === 1 ? $match[1] : null;
    }

    /** 這個套件對到的技術是不是 VERSIONED_TECHNIQUES 之一（GitService 只記這些套件的版本限制） */
    public static function isVersioned(string $ecosystem, string $name): bool
    {
        $technique = self::techniqueFor($ecosystem, $name);

        return $technique !== null && in_array(mb_strtolower($technique[0]), self::VERSIONED_TECHNIQUES, true);
    }

    /**
     * @param  array{0: string, 1: string}  $technique  這個套件對到的 [技術名稱, scope]
     * @param  array<string, mixed>  $versions
     */
    private static function majorVersionOf(string $ecosystem, string $name, array $technique, array $versions): ?string
    {
        if (! in_array(mb_strtolower($technique[0]), self::VERSIONED_TECHNIQUES, true)) {
            return null;
        }

        $byName = $versions[$ecosystem] ?? null;
        $constraint = is_array($byName) ? ($byName[mb_strtolower($name)] ?? null) : null;

        return is_string($constraint) ? self::majorVersion($constraint) : null;
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
