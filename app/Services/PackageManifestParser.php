<?php

namespace App\Services;

/**
 * 從套件清單檔的內容取出套件名稱。
 *
 * 只負責「讀懂格式」，不判斷套件算不算技術（那是 PackageTechniqueMap 的事）。
 * 壞掉或讀不懂的內容回空陣列，不丟例外：一個 repo 的清單檔壞了不該讓整次同步中斷。
 */
final class PackageManifestParser
{
    /** 清單檔的檔名 → 套件生態系（config/package_technologies.php 的第一層 key） */
    public const MANIFEST_FILES = [
        'package.json' => 'npm',
        'composer.json' => 'composer',
        'requirements.txt' => 'pip',
    ];

    /** npm 與 composer 裡放套件名稱的區塊；composer 的 `php`、`ext-*` 不是套件，另外排除 */
    private const JSON_SECTIONS = [
        'npm' => ['dependencies', 'devDependencies', 'optionalDependencies'],
        'composer' => ['require', 'require-dev'],
    ];

    /** 依檔案路徑判斷生態系；不是認得的清單檔回 null */
    public static function ecosystemFor(string $path): ?string
    {
        return self::MANIFEST_FILES[basename($path)] ?? null;
    }

    /** UTF-8 的位元組順序記號（BOM）：Windows 的編輯器存檔常會在檔案開頭多加這三個位元組 */
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @return list<string> 小寫、去重後的套件名稱
     */
    public static function parse(string $ecosystem, string $content): array
    {
        $content = self::withoutBom($content);

        $names = match ($ecosystem) {
            'npm', 'composer' => self::parseJson($ecosystem, $content),
            'pip' => self::parseRequirements($content),
            default => [],
        };

        return array_values(array_unique($names));
    }

    /**
     * 套件名稱 → 清單檔寫的版本限制（例如 `vue` → `^3.5.0`），只有 package.json 與 composer.json
     * 有這個欄位；requirements.txt 與不認得的生態系回空陣列。同一個套件同時在 dependencies 與
     * devDependencies 時，以先出現的區塊（dependencies）為準。限制的內容原樣回傳，
     * 怎麼取主版號不是這裡的事（見 PackageTechniqueMap::majorVersion）。
     *
     * @return array<string, string>
     */
    public static function parseVersionConstraints(string $ecosystem, string $content): array
    {
        if (! isset(self::JSON_SECTIONS[$ecosystem])) {
            return [];
        }

        $constraints = [];

        foreach (self::jsonDependencies($ecosystem, self::withoutBom($content)) as $name => $constraint) {
            if (is_string($constraint)) {
                $constraints[(string) $name] = $constraint;
            }
        }

        return $constraints;
    }

    /** 去掉開頭的 BOM（只去一個）；不然 json_decode 會失敗，requirements.txt 的第一行也會對不上 */
    private static function withoutBom(string $content): string
    {
        return str_starts_with($content, self::UTF8_BOM) ? substr($content, strlen(self::UTF8_BOM)) : $content;
    }

    /** @return list<string> */
    private static function parseJson(string $ecosystem, string $content): array
    {
        // 純數字的套件名稱會被 PHP 轉成整數 key，轉回字串
        return array_map('strval', array_keys(self::jsonDependencies($ecosystem, $content)));
    }

    /**
     * 讀出 JSON 清單檔各區塊的「小寫套件名稱 → 原始值」，同名的以先出現的區塊為準。
     * 內容壞掉回空陣列；composer 的平台需求（php、ext-json、lib-curl）沒有 vendor/ 前綴，不是套件。
     *
     * @return array<string, mixed>
     */
    private static function jsonDependencies(string $ecosystem, string $content): array
    {
        $manifest = json_decode($content, true);

        if (! is_array($manifest)) {
            return [];
        }

        $dependencies = [];

        foreach (self::JSON_SECTIONS[$ecosystem] as $section) {
            if (! is_array($manifest[$section] ?? null)) {
                continue;
            }

            foreach ($manifest[$section] as $name => $constraint) {
                $name = mb_strtolower((string) $name);

                if ($ecosystem === 'composer' && ! str_contains($name, '/')) {
                    continue;
                }

                if (! array_key_exists($name, $dependencies)) {
                    $dependencies[$name] = $constraint;
                }
            }
        }

        return $dependencies;
    }

    /**
     * requirements.txt 一行一個需求：`name==1.0`、`name>=1,<2`、`name[extra]~=1.0`、
     * `name ; python_version < "3.9"`。註解、空行、選項（`-r`、`-e`、`--index-url`）、
     * 網址與本機路徑沒有套件名稱可取，略過。名稱依 PEP 503 正規化（小寫，`_`、`.` 視為 `-`）。
     *
     * @return list<string>
     */
    private static function parseRequirements(string $content): array
    {
        $names = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim(preg_replace('/(^|\s)#.*$/', '', $line));

            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]*)\s*(\[[^\]]*\])?\s*(?:$|[=<>!~;@(\s])/', $line, $match) !== 1) {
                continue;
            }

            // `name @ https://…` 是直接指定來源的寫法，名稱還是 @ 前面那一段，所以保留
            $names[] = preg_replace('/[-_.]+/', '-', mb_strtolower($match[1]));
        }

        return $names;
    }
}
