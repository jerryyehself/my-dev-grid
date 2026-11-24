<?php

namespace Tests\Concerns;

use RuntimeException;

/**
 * 讀回匯出的 Turtle，用 PHP 檢查 qSKOS 那些「必須為 0」的品質項目。
 *
 * CI 不跑 qSKOS（要用 Maven 從原始碼建、執行時要連 w3.org），所以把驗收清單裡的每一項照 qSKOS 的定義
 * 寫一份 PHP 版，直接檢查匯出的檔案。只報告、不擋的項目（同形異義造成的標籤重疊 `ol`、孤立概念 `oc`、
 * 沒有範圍說明 `uc`、語言相關、沒連到外部詞表等）不在這裡。
 *
 * Turtle 只讀得懂匯出用到的那一部分（`@prefix`、完整 IRI、前綴名稱、`a`、`;`、`,`、字串加語言標記或
 * `^^` 型別、`true`／`false`、`#` 註解）；讀到別的寫法就丟例外，所以匯出的語法寫錯時測試會失敗，不會被略過。
 */
trait ChecksSkosQuality
{
    /** 必須為 0 的項目（qSKOS 的代號）。 */
    protected const SKOS_MUST_BE_ZERO = ['ipl', 'dlv', 'rc', 'mc', 'chr', 'rrc', 'urc', 'hr', 'tchbc', 'otc', 'usr', 'el', 'ml', 'ucil', 'husv'];

    private const SKOS_NS = 'http://www.w3.org/2004/02/skos/core#';

    private const RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    private const XSD_BOOLEAN = 'http://www.w3.org/2001/XMLSchema#boolean';

    /** SKOS Reference 定義的所有詞（`usr` 用）。 */
    private const SKOS_TERMS = [
        'Concept', 'ConceptScheme', 'Collection', 'OrderedCollection',
        'inScheme', 'hasTopConcept', 'topConceptOf',
        'prefLabel', 'altLabel', 'hiddenLabel', 'notation',
        'note', 'changeNote', 'definition', 'editorialNote', 'example', 'historyNote', 'scopeNote',
        'semanticRelation', 'broader', 'narrower', 'related', 'broaderTransitive', 'narrowerTransitive',
        'member', 'memberList',
        'mappingRelation', 'closeMatch', 'exactMatch', 'broadMatch', 'narrowMatch', 'relatedMatch',
    ];

    /**
     * 把 Turtle 讀成三元組。受詞是 `['iri' => …]`，或 `['literal' => …, 'lang' => …|null, 'datatype' => …|null]`。
     *
     * @return list<array{0: string, 1: string, 2: array<string, string|null>}>
     */
    protected function parseTurtle(string $turtle): array
    {
        $tokens = $this->turtleTokens($turtle);
        $prefixes = [];
        $triples = [];
        $pos = 0;

        while ($pos < count($tokens)) {
            if ($tokens[$pos][0] === 'prefix') {
                $name = $this->expectToken($tokens, $pos + 1, 'pname');
                $namespace = $this->expectToken($tokens, $pos + 2, 'iri');
                $this->expectToken($tokens, $pos + 3, '.');
                if ($name[2] !== '') {
                    throw new RuntimeException("@prefix 的名稱寫錯：{$name[1]}{$name[2]}");
                }
                $prefixes[substr($name[1], 0, -1)] = $namespace[1];
                $pos += 4;

                continue;
            }

            $subject = $this->turtleIri($tokens[$pos], $prefixes);
            $pos++;
            while (true) {
                $verb = $tokens[$pos] ?? throw new RuntimeException('檔案在述詞的位置結束了');
                $predicate = $verb[0] === 'a' ? self::RDF_TYPE : $this->turtleIri($verb, $prefixes);
                $pos++;
                while (true) {
                    $triples[] = [$subject, $predicate, $this->turtleObject($tokens, $pos, $prefixes)];
                    if (($tokens[$pos][0] ?? null) !== ',') {
                        break;
                    }
                    $pos++;
                }
                $separator = $tokens[$pos][0] ?? null;
                $pos++;
                if ($separator === '.') {
                    break;
                }
                if ($separator !== ';') {
                    throw new RuntimeException('述詞之後要接 ; 或 .');
                }
                if (($tokens[$pos][0] ?? null) === '.') {
                    $pos++;
                    break;
                }
            }
        }

        return $triples;
    }

    /**
     * 每個必須為 0 的項目各找到哪些違規（qSKOS 代號 => 描述），沒有違規的項目是空陣列。
     *
     * @param  list<array{0: string, 1: string, 2: array<string, string|null>}>  $triples
     * @return array<string, list<string>>
     */
    protected function skosProblems(array $triples): array
    {
        $skos = fn (string $term) => self::SKOS_NS.$term;
        $isIri = fn (array $object) => isset($object['iri']);
        $pairs = function (string $term) use ($triples, $skos, $isIri) {
            $result = [];
            foreach ($triples as [$s, $p, $o]) {
                if ($p === $skos($term) && $isIri($o)) {
                    $result[] = [$s, $o['iri']];
                }
            }

            return $result;
        };

        $typed = fn (string $type) => array_values(array_unique(array_map(
            fn (array $triple) => $triple[0],
            array_filter($triples, fn (array $t) => $t[1] === self::RDF_TYPE && ($t[2]['iri'] ?? null) === $skos($type)),
        )));
        $concepts = $typed('Concept');
        $schemes = $typed('ConceptScheme');

        $labels = [];
        foreach ($triples as [$s, $p, $o]) {
            foreach (['prefLabel', 'altLabel', 'hiddenLabel'] as $term) {
                if ($p === $skos($term) && isset($o['literal'])) {
                    $labels[] = [$s, $term, $o['literal'], $o['lang'] ?? ''];
                }
            }
        }

        // 上下位：broader（含 broaderTransitive）加上反著讀的 narrower，自己連自己的另外算 rrc
        $parents = [];
        foreach ([...$pairs('broader'), ...$pairs('broaderTransitive')] as [$child, $parent]) {
            $parents[$child][$parent] = true;
        }
        foreach ([...$pairs('narrower'), ...$pairs('narrowerTransitive')] as [$parent, $child]) {
            $parents[$child][$parent] = true;
        }
        $ancestors = function (string $start) use ($parents): array {
            $seen = [];
            $queue = array_keys(array_diff_key($parents[$start] ?? [], [$start => true]));
            while ($queue !== []) {
                $current = array_shift($queue);
                if (isset($seen[$current])) {
                    continue;
                }
                $seen[$current] = true;
                foreach (array_keys($parents[$current] ?? []) as $next) {
                    if ($next !== $current) {
                        $queue[] = $next;
                    }
                }
            }

            return $seen;
        };

        $problems = array_fill_keys(self::SKOS_MUST_BE_ZERO, []);

        // ipl（S14）：同一個資源、同一個語言有兩個以上 prefLabel
        $prefCount = [];
        foreach ($labels as [$s, $term, $value, $lang]) {
            if ($term === 'prefLabel') {
                $prefCount["{$s} @{$lang}"][] = $value;
            }
        }
        foreach ($prefCount as $key => $values) {
            if (count($values) > 1) {
                $problems['ipl'][] = $key;
            }
        }

        // dlv（S13）：同一個資源的同一個字串（同語言）出現在兩種標籤
        $labelKinds = [];
        foreach ($labels as [$s, $term, $value, $lang]) {
            $labelKinds["{$s} \"{$value}\"@{$lang}"][$term] = true;
        }
        foreach ($labelKinds as $key => $kinds) {
            if (count($kinds) > 1) {
                $problems['dlv'][] = $key;
            }
        }

        // el：空字串或只有空白的標籤；ucil：標籤裡有控制字元
        foreach ($labels as [$s, $term, $value, $lang]) {
            if (preg_match('/\A[\s\p{Z}]*\z/u', $value) === 1) {
                $problems['el'][] = "{$s} {$term}";
            }
            if (preg_match('/\p{Cc}/u', $value) === 1) {
                $problems['ucil'][] = "{$s} {$term}";
            }
        }

        // ml：概念或詞表沒有 prefLabel
        $hasPref = array_flip(array_map(fn (array $label) => $label[0], array_filter($labels, fn (array $label) => $label[1] === 'prefLabel')));
        foreach ([...$concepts, ...$schemes] as $resource) {
            if (! isset($hasPref[$resource])) {
                $problems['ml'][] = $resource;
            }
        }

        // chr：上下位繞成環（不算自己連自己）
        foreach (array_keys($parents) as $node) {
            if (isset($ancestors($node)[$node])) {
                $problems['chr'][] = $node;
            }
        }

        // rrc：上下位或相關連到自己
        foreach (['broader', 'narrower', 'related', 'broaderTransitive', 'narrowerTransitive', 'semanticRelation'] as $term) {
            foreach ($pairs($term) as [$a, $b]) {
                if ($a === $b) {
                    $problems['rrc'][] = "{$a} {$term}";
                }
            }
        }

        // urc：只有一個方向（broader 沒有對應的 narrower，或相關只寫了一邊）
        $has = [];
        foreach (['broader', 'narrower', 'related'] as $term) {
            foreach ($pairs($term) as [$a, $b]) {
                $has[$term]["{$a} {$b}"] = true;
            }
        }
        foreach (['broader' => 'narrower', 'narrower' => 'broader', 'related' => 'related'] as $term => $inverse) {
            foreach ($pairs($term) as [$a, $b]) {
                if (! isset($has[$inverse]["{$b} {$a}"])) {
                    $problems['urc'][] = "{$a} {$term} {$b}";
                }
            }
        }

        // rc（S27）：標了相關的兩個概念之間有上下位路徑
        foreach ($pairs('related') as [$a, $b]) {
            if ($a !== $b && (isset($ancestors($a)[$b]) || isset($ancestors($b)[$a]))) {
                $problems['rc'][] = "{$a} related {$b}";
            }
        }

        // hr：直接的上位也能經另一個上位走到
        foreach ($parents as $child => $direct) {
            foreach (array_keys($direct) as $parent) {
                foreach (array_keys($direct) as $other) {
                    if ($parent !== $child && $other !== $parent && $other !== $child && isset($ancestors($other)[$parent])) {
                        $problems['hr'][] = "{$child} broader {$parent}";
                        break;
                    }
                }
            }
        }

        // mc（S46）：同一對概念同時有 exactMatch 與 broadMatch／narrowMatch／relatedMatch（exactMatch 是對稱的，不分方向）
        $exact = [];
        foreach ($pairs('exactMatch') as [$a, $b]) {
            $exact[min($a, $b).' '.max($a, $b)] = true;
        }
        foreach (['broadMatch', 'narrowMatch', 'relatedMatch'] as $term) {
            foreach ($pairs($term) as [$a, $b]) {
                if (isset($exact[min($a, $b).' '.max($a, $b)])) {
                    $problems['mc'][] = "{$a} {$term} {$b}";
                }
            }
        }

        // tchbc：頂層概念卻有上位；otc：詞表沒有任何頂層概念
        $topOf = [];
        foreach ($pairs('topConceptOf') as [$concept, $scheme]) {
            $topOf[$scheme][$concept] = true;
        }
        foreach ($pairs('hasTopConcept') as [$scheme, $concept]) {
            $topOf[$scheme][$concept] = true;
        }
        foreach ($topOf as $tops) {
            foreach (array_keys($tops) as $concept) {
                if (($parents[$concept] ?? []) !== []) {
                    $problems['tchbc'][] = $concept;
                }
            }
        }
        foreach ($schemes as $scheme) {
            if (($topOf[$scheme] ?? []) === []) {
                $problems['otc'][] = $scheme;
            }
        }

        // usr：用了 SKOS 命名空間裡沒有定義的詞
        foreach ($triples as [$s, $p, $o]) {
            foreach ([$s, $p, $o['iri'] ?? null] as $iri) {
                if (is_string($iri) && str_starts_with($iri, self::SKOS_NS) && ! in_array(substr($iri, strlen(self::SKOS_NS)), self::SKOS_TERMS, true)) {
                    $problems['usr'][] = $iri;
                }
            }
        }

        // husv：主詞或受詞的 IRI 不是 http(s)
        foreach ($triples as [$s, $p, $o]) {
            foreach ([$s, $o['iri'] ?? null] as $iri) {
                if (is_string($iri) && preg_match('~\Ahttps?://~', $iri) !== 1) {
                    $problems['husv'][] = $iri;
                }
            }
        }

        return array_map(fn (array $found) => array_values(array_unique($found)), $problems);
    }

    /**
     * 有違規的項目（qSKOS 代號），依字母排序。
     *
     * @return list<string>
     */
    protected function failingSkosChecks(string $turtle): array
    {
        $failing = array_keys(array_filter($this->skosProblems($this->parseTurtle($turtle))));
        sort($failing);

        return $failing;
    }

    /** @return list<array{0: string, 1: string, 2?: string}> */
    private function turtleTokens(string $turtle): array
    {
        $patterns = [
            'skip' => '\s+|\#[^\n]*',
            'prefix' => '@prefix(?![A-Za-z])',
            'iri' => '<([^<>"{}|^`\\\\\x00-\x20]*)>',
            'string' => '"((?:[^"\\\\\n\r]|\\\\(?:[tbnrf"\'\\\\]|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}))*)"(?:@([a-zA-Z]+(?:-[a-zA-Z0-9]+)*))?',
            'pname' => '([A-Za-z][A-Za-z0-9_-]*)?:((?:[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_-])?)?)',
            'bool' => '(true|false)(?![A-Za-z0-9_:-])',
            'a' => 'a(?![A-Za-z0-9_:-])',
            'datatype' => '\^\^',
            'punct' => '[.;,]',
        ];

        $tokens = [];
        $offset = 0;
        $length = strlen($turtle);
        while ($offset < $length) {
            foreach ($patterns as $kind => $pattern) {
                if (preg_match('/\G(?:'.$pattern.')/u', $turtle, $match, 0, $offset) === 1 && $match[0] !== '') {
                    $offset += strlen($match[0]);
                    $token = match ($kind) {
                        'skip' => null,
                        'prefix', 'a', 'datatype' => [$kind],
                        'iri' => ['iri', $match[1]],
                        'string' => ['string', $this->unescapeTurtle($match[1]), $match[2] ?? ''],
                        'pname' => ['pname', ($match[1] ?? '').':', $match[2] ?? ''],
                        'bool' => ['bool', $match[1]],
                        'punct' => [$match[0]],
                    };
                    if ($token !== null) {
                        $tokens[] = $token;
                    }

                    continue 2;
                }
            }

            throw new RuntimeException('Turtle 讀不懂的地方：'.substr($turtle, $offset, 40));
        }

        return $tokens;
    }

    private function unescapeTurtle(string $value): string
    {
        return preg_replace_callback('/\\\\(u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s', fn (array $m) => match ($m[1][0]) {
            't' => "\t", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 'f' => "\f",
            'u', 'U' => mb_chr((int) hexdec(substr($m[1], 1)), 'UTF-8'),
            default => $m[1],
        }, $value);
    }

    /**
     * @param  list<array<int, string>>  $tokens
     * @return array<int, string>
     */
    private function expectToken(array $tokens, int $pos, string $kind): array
    {
        $token = $tokens[$pos] ?? null;
        if ($token === null || $token[0] !== $kind) {
            throw new RuntimeException("這裡應該是 {$kind}");
        }

        return $token;
    }

    /**
     * @param  array<int, string>  $token
     * @param  array<string, string>  $prefixes
     */
    private function turtleIri(array $token, array $prefixes): string
    {
        if ($token[0] === 'iri') {
            return $token[1];
        }
        if ($token[0] === 'pname') {
            $prefix = substr($token[1], 0, -1);

            return ($prefixes[$prefix] ?? throw new RuntimeException("沒有宣告的前綴：{$prefix}")).$token[2];
        }

        throw new RuntimeException("這裡應該是 IRI，讀到 {$token[0]}");
    }

    /**
     * @param  list<array<int, string>>  $tokens
     * @param  array<string, string>  $prefixes
     * @return array<string, string|null>
     */
    private function turtleObject(array $tokens, int &$pos, array $prefixes): array
    {
        $token = $tokens[$pos] ?? throw new RuntimeException('檔案在受詞的位置結束了');
        $pos++;

        if ($token[0] === 'bool') {
            return ['literal' => $token[1], 'lang' => null, 'datatype' => self::XSD_BOOLEAN];
        }
        if ($token[0] !== 'string') {
            return ['iri' => $this->turtleIri($token, $prefixes)];
        }

        $datatype = null;
        if (($tokens[$pos][0] ?? null) === 'datatype') {
            if ($token[2] !== '') {
                throw new RuntimeException('字串不能同時有語言標記與型別');
            }
            $datatype = $this->turtleIri($tokens[$pos + 1] ?? throw new RuntimeException('^^ 後面沒有型別'), $prefixes);
            $pos += 2;
        }

        return ['literal' => $token[1], 'lang' => $token[2] === '' ? null : $token[2], 'datatype' => $datatype];
    }
}
