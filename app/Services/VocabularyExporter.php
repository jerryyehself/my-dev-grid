<?php

namespace App\Services;

use App\Enums\ResLabelType;
use App\Models\Nomen;
use App\Models\Technique;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 把技術詞表匯出成 SKOS（Turtle）。
 *
 * 內容：
 * - 一個 `skos:ConceptScheme`（`{base_uri}vocabulary`），名稱是設定的 `scheme_label`。
 * - 每個技術（含 Technique 底下的 concept，不含已軟刪除的）一個 `skos:Concept`（`{base_uri}technique/{id}`），
 *   正式名（`skos:prefLabel`）是 `Technique::labelFrom(title, version)`，語言標記是設定的 `language`。
 *   專案（implementations）不在詞表裡。分類（language、framework、concept…）是類別，不是概念之間的上下位，不輸出。
 * - 上下位與相關照 `SemanticRelations::fromDatabase()` 讀：broader／narrower／related 加上掛在它們底下的子述詞
 *   （例如 isVersionOf 掛到 broader 底下之後也算 broader）。資料庫只存一個方向，這裡兩個方向都輸出
 *   （broader 配 narrower、related 兩端各一條），不然 qSKOS 會報單向關係（`urc`）。
 * - 沒有上位、也沒有停用的概念是頂層概念：`skos:topConceptOf` 與詞表的 `skos:hasTopConcept` 兩邊都寫。
 * - 停用的技術照 Skosmos 的慣例：`owl:deprecated true`，有改用對象的加 `dcterms:isReplacedBy`。
 *   停用的概念仍然在詞表裡（識別碼要打得開），但不當頂層概念。改用對象已軟刪除時不寫 isReplacedBy，
 *   免得指到詞表裡沒有的識別碼。
 * - 別名（`res_labels` 的 alt、hidden → `skos:altLabel`、`skos:hiddenLabel`）預設不輸出，要呼叫端明白打開。
 *   語言標記用別名的 `locale`，沒填或不是合法的語言標記就用預設的 `language`。跟正式名一字不差（同語言，
 *   語言標記不分大小寫）的別名不輸出：同一個字串不能同時是 prefLabel 與 altLabel／hiddenLabel（SKOS S13）。
 *
 * 識別碼的前綴放在 `config/vocabulary.php`，程式不寫死網域。設定不合格（不是 http(s)、沒有以 `/` 結尾、
 * 語言標記不合法、詞表名稱空白）時直接丟例外，不產出半套的檔案。
 *
 * 沒有用 RDF 函式庫，Turtle 是手寫的：只用到完整 IRI、`a`、`;`、`,`、帶語言標記的字串與 `true`，
 * 字串照 Turtle 1.1 的 STRING_LITERAL_QUOTE 跳脫。輸出依 id 排序，同樣的資料每次產出一樣的檔案。
 */
final class VocabularyExporter
{
    private const SKOS = 'http://www.w3.org/2004/02/skos/core#';

    private const OWL = 'http://www.w3.org/2002/07/owl#';

    private const DCTERMS = 'http://purl.org/dc/terms/';

    /** Turtle 的前綴宣告，順序就是輸出的順序。 */
    private const PREFIXES = [
        'dcterms' => self::DCTERMS,
        'owl' => self::OWL,
        'skos' => self::SKOS,
    ];

    public function __construct(private readonly Repository $config) {}

    /** 詞表本身的識別碼。 */
    public function schemeUri(): string
    {
        return $this->baseUri().'vocabulary';
    }

    /** 一個技術的識別碼。 */
    public function conceptUri(int $techniqueId): string
    {
        return $this->baseUri().'technique/'.$techniqueId;
    }

    /**
     * 整份詞表的 Turtle。
     *
     * @param  bool  $withAliases  要不要輸出 `res_labels` 的別名（altLabel、hiddenLabel）
     *
     * @throws InvalidArgumentException `config/vocabulary.php` 的設定不合格
     */
    public function toTurtle(bool $withAliases = false): string
    {
        $language = $this->language();
        $scheme = $this->schemeUri();
        $schemeLabel = $this->schemeLabel();

        $techniques = Technique::orderBy('id')->get(['id', 'title', 'version', 'deprecated_at', 'replaced_by_id'])->keyBy('id');
        $relations = SemanticRelations::fromDatabase();

        // SemanticRelations 只收兩端都還在的邊；這裡再對一次技術清單，兩邊的「還在」定義才不會走散
        $broader = [];
        $narrower = [];
        foreach ($relations->broader() as $child => $parents) {
            foreach ($parents as $parent) {
                if ($techniques->has($child) && $techniques->has($parent)) {
                    $broader[$child][] = $parent;
                    $narrower[$parent][] = $child;
                }
            }
        }
        $related = [];
        foreach ($relations->relatedPairs() as [$a, $b]) {
            if ($techniques->has($a) && $techniques->has($b)) {
                $related[$a][] = $b;
                $related[$b][] = $a;
            }
        }

        $aliases = $withAliases ? $this->aliases($techniques->keys()->all(), $language) : [];

        $topConcepts = $techniques->filter(fn (Technique $technique) => ! $technique->isDeprecated() && ! isset($broader[$technique->id]))->keys()->all();

        $blocks = [];
        $blocks[] = $this->block($scheme, 'skos:ConceptScheme', [
            'skos:prefLabel' => [self::literal($schemeLabel, $language)],
            'skos:hasTopConcept' => array_map(fn (int $id) => self::iri($this->conceptUri($id)), $topConcepts),
        ]);

        foreach ($techniques as $id => $technique) {
            $prefLabel = Technique::labelFrom((string) $technique->title, $technique->version);
            $properties = [
                'skos:inScheme' => [self::iri($scheme)],
                'skos:topConceptOf' => in_array($id, $topConcepts, true) ? [self::iri($scheme)] : [],
                'skos:prefLabel' => [self::literal($prefLabel, $language)],
            ];

            foreach ([ResLabelType::Alt->value => 'skos:altLabel', ResLabelType::Hidden->value => 'skos:hiddenLabel'] as $type => $predicate) {
                $properties[$predicate] = array_map(
                    fn (array $alias) => self::literal($alias['label'], $alias['language']),
                    array_values(array_filter(
                        $aliases[$id][$type] ?? [],
                        fn (array $alias) => ! ($alias['label'] === $prefLabel && strcasecmp($alias['language'], $language) === 0),
                    )),
                );
            }

            foreach (['skos:broader' => $broader, 'skos:narrower' => $narrower, 'skos:related' => $related] as $predicate => $edges) {
                $others = array_values(array_unique($edges[$id] ?? []));
                sort($others);
                $properties[$predicate] = array_map(fn (int $other) => self::iri($this->conceptUri($other)), $others);
            }

            if ($technique->isDeprecated()) {
                $properties['owl:deprecated'] = ['true'];
                $replacement = $technique->replaced_by_id === null ? null : (int) $technique->replaced_by_id;
                $properties['dcterms:isReplacedBy'] = $replacement !== null && $replacement !== $id && $techniques->has($replacement)
                    ? [self::iri($this->conceptUri($replacement))]
                    : [];
            }

            $blocks[] = $this->block($this->conceptUri($id), 'skos:Concept', $properties);
        }

        $prefixes = '';
        foreach (self::PREFIXES as $prefix => $namespace) {
            $prefixes .= "@prefix {$prefix}: <{$namespace}> .\n";
        }

        return $prefixes."\n".implode("\n", $blocks);
    }

    /**
     * 一個主詞的整段 Turtle：先寫型別，再依 $properties 的順序寫，沒有值的屬性略過。
     *
     * @param  array<string, list<string>>  $properties  前綴名稱 => 已經寫成 Turtle 的值
     */
    private function block(string $subject, string $type, array $properties): string
    {
        $lines = ["a {$type}"];
        foreach ($properties as $predicate => $objects) {
            if ($objects !== []) {
                $lines[] = $predicate.' '.implode(" ,\n        ", $objects);
            }
        }

        return self::iri($subject).' '.implode(" ;\n    ", $lines)." .\n";
    }

    /**
     * 這些技術的別名，依技術 id 與種類（alt、hidden）分好，依 id 排序。
     *
     * 讀原始的 `label_type` 字串，不經 model 的 enum cast：認不得的種類只會落在 alt、hidden 以外的 key，
     * 呼叫端只讀這兩個 key，所以不會輸出，也不會因為一列壞資料讓整份匯出失敗。
     *
     * @param  list<int>  $techniqueIds
     * @return array<int, array<string, list<array{label: string, language: string}>>>
     */
    private function aliases(array $techniqueIds, string $defaultLanguage): array
    {
        $aliases = [];
        $rows = DB::table('res_labels')->where('res_type', Nomen::RES_TECHNIQUE)->whereIn('res_id', $techniqueIds)->orderBy('id')
            ->get(['res_id', 'label', 'label_type', 'locale']);
        foreach ($rows as $row) {
            $locale = $row->locale;
            $aliases[(int) $row->res_id][(string) $row->label_type][] = [
                'label' => (string) $row->label,
                'language' => is_string($locale) && self::isLanguageTag($locale) ? $locale : $defaultLanguage,
            ];
        }

        return $aliases;
    }

    /** @throws InvalidArgumentException */
    private function baseUri(): string
    {
        $base = $this->config->get('vocabulary.base_uri');

        // 只收 http(s)、有主機、以 / 結尾，而且沒有 Turtle 的 IRI 不能放的字元；? 與 # 也不收，後面接的是路徑
        if (! is_string($base) || preg_match('~\Ahttps?://[^/\x00-\x20<>"{}|^`\\\\?#]+(/[^\x00-\x20<>"{}|^`\\\\?#]*)?/\z~', $base) !== 1) {
            throw new InvalidArgumentException('vocabulary.base_uri 必須是 http 或 https 開頭、以 / 結尾的網址。');
        }

        return $base;
    }

    /** @throws InvalidArgumentException */
    private function language(): string
    {
        $language = $this->config->get('vocabulary.language');

        if (! is_string($language) || ! self::isLanguageTag($language)) {
            throw new InvalidArgumentException('vocabulary.language 必須是語言標記，例如 zh-Hant。');
        }

        return $language;
    }

    /** @throws InvalidArgumentException */
    private function schemeLabel(): string
    {
        $label = $this->config->get('vocabulary.scheme_label');

        if (! is_string($label) || preg_match('/\A[\s\p{Cc}]*\z/u', $label) === 1) {
            throw new InvalidArgumentException('vocabulary.scheme_label 不能是空白。');
        }

        return $label;
    }

    /** Turtle 的 LANGTAG：英文字母開頭，之後是用 - 接起來的英數字段，例如 zh-Hant、en。 */
    private static function isLanguageTag(string $tag): bool
    {
        return preg_match('/\A[a-zA-Z]{1,8}(-[a-zA-Z0-9]{1,8})*\z/', $tag) === 1;
    }

    private static function iri(string $iri): string
    {
        return '<'.$iri.'>';
    }

    /**
     * 帶語言標記的字串。`"`、`\`、換行一定要跳脫；其他控制字元也寫成 \uXXXX，檔案裡才不會有看不見的字元。
     * 不合法的 UTF-8 位元組用 `mb_scrub()` 換成替代字元（`mb_substitute_character()` 的設定，預設是 `?`），
     * 不然整份 Turtle 都讀不進去。
     */
    private static function literal(string $value, string $language): string
    {
        $escaped = preg_replace_callback(
            '/[\x00-\x1F\x7F"\\\\]/u',
            fn (array $match) => match ($match[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04X', ord($match[0])),
            },
            mb_scrub($value, 'UTF-8'),
        );

        return '"'.$escaped.'"@'.$language;
    }
}
