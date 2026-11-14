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

    /**
     * @return list<string> 小寫、去重後的套件名稱
     */
    public static function parse(string $ecosystem, string $content): array
    {
        $names = match ($ecosystem) {
            'npm', 'composer' => self::parseJson($ecosystem, $content),
            'pip' => self::parseRequirements($content),
            default => [],
        };

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private static function parseJson(string $ecosystem, string $content): array
    {
        $manifest = json_decode($content, true);

        if (! is_array($manifest)) {
            return [];
        }

        $names = [];

        foreach (self::JSON_SECTIONS[$ecosystem] as $section) {
            if (! is_array($manifest[$section] ?? null)) {
                continue;
            }

            foreach (array_keys($manifest[$section]) as $name) {
                $name = mb_strtolower((string) $name);

                // composer 的平台需求（php、ext-json、lib-curl）沒有 vendor/ 前綴，不是套件
                if ($ecosystem === 'composer' && ! str_contains($name, '/')) {
                    continue;
                }

                $names[] = $name;
            }
        }

        return $names;
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
