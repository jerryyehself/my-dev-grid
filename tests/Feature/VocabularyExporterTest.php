<?php

namespace Tests\Feature;

use App\Enums\ResLabelType;
use App\Models\EntityRelation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\ResLabel;
use App\Models\Scope;
use App\Models\Technique;
use App\Services\TechniqueMerger;
use App\Services\VocabularyExporter;
use Database\Seeders\GitHubReposSnapshotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\ChecksSkosQuality;
use Tests\TestCase;

/**
 * `VocabularyExporter`：技術詞表匯出成 SKOS Turtle，再用 `ChecksSkosQuality` 讀回來檢查。
 *
 * 夾具：預設資料加上 GitHub 快照同步出來的技術（含同步建的 requires、isVersionOf），把 isVersionOf 掛到
 * broader、requires 掛到 related 底下，當成子述詞；再加一小份概念詞表（PaaS、靜態託管掛在雲端服務底下，
 * Heroku 掛在 PaaS 底下，token 兩個同形異義的概念），以及把舊的 Procfile 合併進 Heroku。
 */
class VocabularyExporterTest extends TestCase
{
    use ChecksSkosQuality;
    use RefreshDatabase;

    private const SKOS = 'http://www.w3.org/2004/02/skos/core#';

    /** @var array<string, Technique> */
    private array $t = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(GitHubReposSnapshotSeeder::class);

        // parent_class 是鎖住的欄位，後台改不了；直接改資料庫，讓同步建的版本與依賴關係也算進上下位與相關
        DB::table('relations')->where('name', 'isVersionOf')->update(['parent_class' => $this->relationId('broader')]);
        DB::table('relations')->where('name', 'requires')->update(['parent_class' => $this->relationId('related')]);

        $concept = $this->scopeId('concept');
        foreach (['雲端服務', 'PaaS', '靜態託管', '資訊安全', 'token（驗證）', 'token（LLM）', 'SPA'] as $title) {
            $this->t[$title] = Technique::create(['type' => $concept, 'title' => $title]);
        }
        $this->t['Cloudflare Pages'] = Technique::create(['type' => $this->scopeId('packagetool'), 'title' => 'Cloudflare Pages']);
        $this->t['Heroku'] = Technique::where('title', 'Heroku')->sole();

        $this->edge('PaaS', '雲端服務', 'broader');
        $this->edge('靜態託管', '雲端服務', 'broader');
        $this->edge('token（驗證）', '資訊安全', 'broader');
        $this->edge('Heroku', 'PaaS', 'broader');
        $this->edge('Cloudflare Pages', '靜態託管', 'broader');
        $this->edge('SPA', 'Cloudflare Pages', 'related');

        // 舊的 Procfile 合併進 Heroku：Procfile 停用、改用 Heroku，舊名成為 Heroku 的隱藏別名
        $this->t['Procfile'] = Technique::create(['type' => $this->scopeId('language'), 'title' => 'Procfile']);
        Implementation::where('title', 'Laravel-LearningLibrary')->sole()->techniques()->attach($this->t['Procfile']->id, ['relation_id' => $this->relationId('usedBy')]);
        app(TechniqueMerger::class)->merge($this->t['Procfile'], $this->t['Heroku']);

        $this->alias('SPA', 'single-page application', ResLabelType::Alt, 'en');
        $this->alias('token（驗證）', 'token', ResLabelType::Alt);
        $this->alias('token（LLM）', 'token', ResLabelType::Alt);
    }

    private function relationId(string $name): int
    {
        return (int) Relation::where('name', $name)->value('id');
    }

    private function scopeId(string $name): int
    {
        return (int) Scope::where('name', $name)->value('id');
    }

    private function edge(string $subject, string $object, string $predicate): EntityRelation
    {
        return EntityRelation::create([
            'entity_type' => 'technique',
            'subject_id' => $this->t[$subject]->id,
            'object_id' => $this->t[$object]->id,
            'relation_id' => $this->relationId($predicate),
        ]);
    }

    private function alias(string $technique, string $label, ResLabelType $type, ?string $locale = null): ResLabel
    {
        return ResLabel::create(['res_type' => 'technique', 'res_id' => $this->t[$technique]->id, 'label' => $label, 'label_type' => $type, 'locale' => $locale]);
    }

    private function exporter(): VocabularyExporter
    {
        return app(VocabularyExporter::class);
    }

    private function uri(string $technique): string
    {
        return $this->exporter()->conceptUri($this->t[$technique]->id);
    }

    /**
     * 某個主詞、某個 SKOS（或完整 IRI）述詞的所有受詞，IRI 回字串、字串回 [值, 語言]，依字典序排好。
     *
     * @param  list<array{0: string, 1: string, 2: array<string, string|null>}>  $triples
     * @return list<string|array{0: string, 1: string|null}>
     */
    private function objects(array $triples, string $subject, string $predicate): array
    {
        $predicate = str_contains($predicate, '://') ? $predicate : self::SKOS.$predicate;
        $found = [];
        foreach ($triples as [$s, $p, $o]) {
            if ($s === $subject && $p === $predicate) {
                $found[] = isset($o['iri']) ? $o['iri'] : [$o['literal'], $o['lang']];
            }
        }
        sort($found);

        return $found;
    }

    /** @return iterable<string, array{bool}> */
    public static function aliasModes(): iterable
    {
        yield '不含別名（預設）' => [false];
        yield '含別名' => [true];
    }

    #[DataProvider('aliasModes')]
    #[TestDox('夾具詞表匯出後，必須為 0 的品質項目全部是 0')]
    public function test_the_fixture_vocabulary_passes_every_must_be_zero_check(bool $withAliases): void
    {
        $problems = $this->skosProblems($this->parseTurtle($this->exporter()->toTurtle($withAliases)));

        // 每一項都有跑到，不是少算
        $this->assertEqualsCanonicalizing(self::SKOS_MUST_BE_ZERO, array_keys($problems));
        $this->assertSame([], array_filter($problems));
    }

    #[TestDox('一個詞表；每個還在的技術各一個概念，正式名是名稱加版本，不含專案與已刪除的')]
    public function test_every_technique_becomes_one_concept_in_one_scheme(): void
    {
        $framework = $this->scopeId('framework');
        $versioned = Technique::create(['type' => $framework, 'title' => '匯出測試框架', 'version' => '2']);
        $trashed = Technique::create(['type' => $framework, 'title' => 'vocabulary-export-trashed-marker']);
        $trashed->delete();

        $turtle = $this->exporter()->toTurtle();
        $triples = $this->parseTurtle($turtle);
        $scheme = $this->exporter()->schemeUri();
        $typeOf = fn (string $type) => array_values(array_map(fn ($t) => $t[0], array_filter($triples, fn ($t) => $t[1] === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type' && ($t[2]['iri'] ?? null) === self::SKOS.$type)));

        $this->assertSame([$scheme], $typeOf('ConceptScheme'));
        $this->assertSame([['技術詞表', 'zh-Hant']], $this->objects($triples, $scheme, 'prefLabel'));

        $expected = Technique::orderBy('id')->pluck('id')->map(fn ($id) => $this->exporter()->conceptUri((int) $id))->all();
        $this->assertSame($expected, $typeOf('Concept'));
        $this->assertNotContains($this->exporter()->conceptUri($trashed->id), $typeOf('Concept'));
        $this->assertStringNotContainsString('vocabulary-export-trashed-marker', $turtle);
        $this->assertStringNotContainsString('implementation', $turtle);
        $this->assertStringNotContainsString('Laravel-LearningLibrary', $turtle);

        foreach (Technique::all() as $technique) {
            $concept = $this->exporter()->conceptUri($technique->id);
            $this->assertSame([[$technique->label, 'zh-Hant']], $this->objects($triples, $concept, 'prefLabel'));
            $this->assertSame([$scheme], $this->objects($triples, $concept, 'inScheme'));
        }
        $this->assertSame([['匯出測試框架 2', 'zh-Hant']], $this->objects($triples, $this->exporter()->conceptUri($versioned->id), 'prefLabel'));
    }

    #[TestDox('上下位與相關兩個方向都寫，子述詞推出的也算；沒有上位的是頂層概念')]
    public function test_hierarchy_and_related_are_written_in_both_directions(): void
    {
        $framework = $this->scopeId('framework');
        $this->t['框架'] = Technique::create(['type' => $framework, 'title' => '匯出測試框架']);
        $this->t['框架 2'] = Technique::create(['type' => $framework, 'title' => '匯出測試框架', 'version' => '2']);
        $this->t['語言'] = Technique::create(['type' => $this->scopeId('language'), 'title' => '匯出測試語言']);
        $this->edge('框架 2', '框架', 'isVersionOf');
        $this->edge('框架', '語言', 'requires');
        // 反著存的 narrower 列也讀成同一條上下位
        $this->edge('資訊安全', 'token（LLM）', 'narrower');

        $triples = $this->parseTurtle($this->exporter()->toTurtle());
        $scheme = $this->exporter()->schemeUri();

        $this->assertSame([$this->uri('PaaS')], $this->objects($triples, $this->uri('Heroku'), 'broader'));
        $this->assertSame([$this->uri('Heroku')], $this->objects($triples, $this->uri('PaaS'), 'narrower'));
        $this->assertEqualsCanonicalizing([$this->uri('PaaS'), $this->uri('靜態託管')], $this->objects($triples, $this->uri('雲端服務'), 'narrower'));
        $this->assertSame([$this->uri('Cloudflare Pages')], $this->objects($triples, $this->uri('SPA'), 'related'));
        $this->assertSame([$this->uri('SPA')], $this->objects($triples, $this->uri('Cloudflare Pages'), 'related'));

        // 子述詞：isVersionOf 輸出成 broader／narrower，requires 輸出成 related
        $this->assertSame([$this->uri('框架')], $this->objects($triples, $this->uri('框架 2'), 'broader'));
        $this->assertSame([$this->uri('框架 2')], $this->objects($triples, $this->uri('框架'), 'narrower'));
        $this->assertSame([$this->uri('語言')], $this->objects($triples, $this->uri('框架'), 'related'));
        $this->assertSame([$this->uri('框架')], $this->objects($triples, $this->uri('語言'), 'related'));
        $this->assertSame([$this->uri('資訊安全')], $this->objects($triples, $this->uri('token（LLM）'), 'broader'));

        // 頂層概念：沒有上位、沒有停用的；兩邊都寫
        $tops = $this->objects($triples, $scheme, 'hasTopConcept');
        foreach (['雲端服務', '資訊安全', '框架', '語言'] as $top) {
            $this->assertContains($this->uri($top), $tops);
            $this->assertSame([$scheme], $this->objects($triples, $this->uri($top), 'topConceptOf'));
        }
        foreach (['PaaS', 'Heroku', '框架 2', 'token（LLM）', 'Procfile'] as $notTop) {
            $this->assertNotContains($this->uri($notTop), $tops);
            $this->assertSame([], $this->objects($triples, $this->uri($notTop), 'topConceptOf'));
        }
    }

    #[TestDox('停用的概念標 owl:deprecated，有改用對象的指過去；改用對象已刪除就不指')]
    public function test_deprecated_techniques_are_marked_and_point_to_their_replacement(): void
    {
        $language = $this->scopeId('language');
        $this->t['只停用'] = Technique::create(['type' => $language, 'title' => '匯出測試只停用']);
        $this->t['只停用']->deprecate(null);
        $this->t['舊'] = Technique::create(['type' => $language, 'title' => '匯出測試舊']);
        $this->t['已刪除'] = Technique::create(['type' => $language, 'title' => '匯出測試已刪除']);
        $this->t['舊']->deprecate($this->t['已刪除']);
        $this->t['已刪除']->delete();

        $triples = $this->parseTurtle($this->exporter()->toTurtle());
        $deprecated = 'http://www.w3.org/2002/07/owl#deprecated';
        $replacedBy = 'http://purl.org/dc/terms/isReplacedBy';

        $this->assertSame([['true', null]], $this->objects($triples, $this->uri('Procfile'), $deprecated));
        $this->assertSame([$this->uri('Heroku')], $this->objects($triples, $this->uri('Procfile'), $replacedBy));
        $this->assertSame([['true', null]], $this->objects($triples, $this->uri('只停用'), $deprecated));
        $this->assertSame([], $this->objects($triples, $this->uri('只停用'), $replacedBy));
        $this->assertSame([['true', null]], $this->objects($triples, $this->uri('舊'), $deprecated));
        $this->assertSame([], $this->objects($triples, $this->uri('舊'), $replacedBy));
        $this->assertSame([], $this->objects($triples, $this->uri('Heroku'), $deprecated));

        $booleans = array_filter($triples, fn ($t) => $t[1] === $deprecated);
        foreach ($booleans as [, , $object]) {
            $this->assertSame('http://www.w3.org/2001/XMLSchema#boolean', $object['datatype']);
        }
    }

    #[TestDox('別名預設不輸出；打開後 alt、hidden 照 locale 輸出，跟正式名相同的不寫')]
    public function test_aliases_are_left_out_unless_asked(): void
    {
        $this->alias('PaaS', 'vocab-alias-marker-alt', ResLabelType::Alt, 'en');
        $this->alias('PaaS', 'vocab-alias-marker-hidden', ResLabelType::Hidden);
        $this->alias('PaaS', 'vocab-alias-marker-bad-locale', ResLabelType::Alt, 'zh_Hant');
        // 認不得的種類（只有直接改資料庫才會有）不輸出
        DB::table('res_labels')->insert([
            'res_type' => 'technique', 'res_id' => $this->t['PaaS']->id, 'label' => 'vocab-unknown-type-marker',
            'normalized' => 'vocabunknowntypemarker', 'label_type' => 'pref', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 跟正式名一字不差：同語言的不寫（SKOS S13），不同語言的照寫
        $this->alias('雲端服務', '雲端服務', ResLabelType::Alt);
        $this->alias('雲端服務', 'PaaS', ResLabelType::Alt, 'en');
        $this->alias('PaaS', 'PaaS', ResLabelType::Hidden, 'en');
        // 語言標記只差大小寫算同一個語言，也不寫
        $this->alias('SPA', 'SPA', ResLabelType::Hidden, 'zh-hant');

        $default = $this->exporter()->toTurtle();
        $this->assertStringNotContainsString('vocab-alias-marker', $default);
        $labelTriples = array_filter($this->parseTurtle($default), fn ($t) => in_array($t[1], [self::SKOS.'altLabel', self::SKOS.'hiddenLabel'], true));
        $this->assertSame([], $labelTriples);

        $withAliases = $this->exporter()->toTurtle(true);
        $this->assertStringNotContainsString('vocab-unknown-type-marker', $withAliases);
        $triples = $this->parseTurtle($withAliases);
        $this->assertSame([['vocab-alias-marker-alt', 'en'], ['vocab-alias-marker-bad-locale', 'zh-Hant']], $this->objects($triples, $this->uri('PaaS'), 'altLabel'));
        $this->assertSame([['PaaS', 'en'], ['vocab-alias-marker-hidden', 'zh-Hant']], $this->objects($triples, $this->uri('PaaS'), 'hiddenLabel'));
        $this->assertSame([['PaaS', 'en']], $this->objects($triples, $this->uri('雲端服務'), 'altLabel'));
        $this->assertSame([], $this->objects($triples, $this->uri('SPA'), 'hiddenLabel'));
        // 合併留下的舊名
        $this->assertSame([['Procfile', 'zh-Hant']], $this->objects($triples, $this->uri('Heroku'), 'hiddenLabel'));
        // 同形異義：兩個概念都有 token 這個別名（qSKOS ol 會標，但不在必須為 0 的清單裡）
        $this->assertSame([['token', 'zh-Hant']], $this->objects($triples, $this->uri('token（驗證）'), 'altLabel'));
        $this->assertSame([['token', 'zh-Hant']], $this->objects($triples, $this->uri('token（LLM）'), 'altLabel'));
        $this->assertSame([], array_filter($this->skosProblems($triples)));
    }

    #[TestDox('名稱裡的引號、反斜線、換行照 Turtle 跳脫，讀回來一字不差')]
    public function test_titles_are_escaped_and_read_back_unchanged(): void
    {
        $titles = ['帶 "引號" 的', 'C:\\path\\to', "兩行\n第二行", "tab\there", '結尾反斜線\\'];
        $ids = [];
        foreach ($titles as $title) {
            $ids[$title] = Technique::create(['type' => $this->scopeId('concept'), 'title' => $title])->id;
        }

        $triples = $this->parseTurtle($this->exporter()->toTurtle());

        foreach ($ids as $title => $id) {
            $this->assertSame([[$title, 'zh-Hant']], $this->objects($triples, $this->exporter()->conceptUri($id), 'prefLabel'));
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function databaseViolations(): iterable
    {
        yield '上下位繞成環（chr）' => ['cycle', ['chr']];
        yield '相關的兩端有上下位路徑（rc）' => ['related_on_path', ['rc']];
        yield '子述詞推出的相關與上下位重疊（rc）' => ['sub_predicates_overlap', ['rc']];
        yield '上位連到自己（rrc）' => ['broader_self', ['rrc']];
        yield '相關連到自己（rrc）' => ['related_self', ['rrc']];
        yield '直接的上位也能經別的上位走到（hr）' => ['redundant_broader', ['hr']];
        yield '名稱只有空白（el）' => ['blank_title', ['el']];
        yield '名稱有控制字元（ucil）' => ['control_character', ['ucil']];
        yield '詞表沒有任何頂層概念（otc）' => ['no_top_concept', ['otc']];
    }

    /**
     * 寫入 API 擋得下這些情況，但資料庫裡仍可能有（直接改資料庫、子述詞改掛之前就有的邊），
     * 所以從資料庫建出違規的資料，匯出後要被對應的檢查抓到，而且只有那一項。
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('databaseViolations')]
    #[TestDox('資料庫裡違規的資料，匯出後被對應的檢查抓到')]
    public function test_violations_in_the_database_are_caught(string $case, array $expected): void
    {
        $concept = $this->scopeId('concept');
        match ($case) {
            'cycle' => $this->edge('雲端服務', 'Heroku', 'broader'),
            'related_on_path' => $this->edge('Heroku', '雲端服務', 'related'),
            'sub_predicates_overlap' => (function () {
                // 同一對技術同時有 isVersionOf（→ broader）與 requires（→ related）
                $this->edge('靜態託管', 'SPA', 'isVersionOf');
                $this->edge('SPA', '靜態託管', 'requires');
            })(),
            'broader_self' => $this->edge('PaaS', 'PaaS', 'broader'),
            'related_self' => $this->edge('SPA', 'SPA', 'related'),
            'redundant_broader' => $this->edge('Heroku', '雲端服務', 'broader'),
            'blank_title' => Technique::create(['type' => $concept, 'title' => " \u{3000} "]),
            'control_character' => Technique::create(['type' => $concept, 'title' => "響鈴\u{0007}字元"]),
            'no_top_concept' => Technique::query()->delete(),
        };

        $this->assertSame($expected, $this->failingSkosChecks($this->exporter()->toTurtle()));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function turtleViolations(): iterable
    {
        $head = "@prefix skos: <http://www.w3.org/2004/02/skos/core#> .\n@prefix ex: <https://example.test/id/> .\n"
            ."ex:s a skos:ConceptScheme ; skos:prefLabel \"詞表\"@zh-Hant ; skos:hasTopConcept ex:top .\n"
            ."ex:top a skos:Concept ; skos:prefLabel \"頂層\"@zh-Hant ; skos:topConceptOf ex:s ; skos:inScheme ex:s .\n";

        yield '乾淨的對照組' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:prefLabel \"A\"@en ; skos:altLabel \"甲\"@en ; skos:inScheme ex:s ; skos:broader ex:top .\nex:top skos:narrower ex:a .\n", []];
        yield '同語言兩個正式名（ipl）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant , \"乙\"@zh-Hant .\n", ['ipl']];
        yield '語言標記只差大小寫也算同一個字串（dlv）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:altLabel \"甲\"@zh-hant .\n", ['dlv']];
        yield '語言標記只差大小寫也算同一個語言（ipl）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant , \"乙\"@ZH-HANT .\n", ['ipl']];
        yield '同一個字串既是正式名又是別名（dlv）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:hiddenLabel \"甲\"@zh-Hant .\n", ['dlv']];
        yield '同一對同時 exactMatch 與 broadMatch（mc）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:exactMatch <https://other.test/x> ; skos:narrowMatch <https://other.test/x> .\n"
            ."<https://other.test/x> skos:broadMatch ex:a ; skos:exactMatch ex:a .\n", ['mc']];
        yield '只有 broader 沒有 narrower（urc）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:broader ex:top .\n", ['urc']];
        yield '只寫 topConceptOf、詞表沒寫 hasTopConcept（urc）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:topConceptOf ex:s .\n", ['urc']];
        yield '相關只寫一邊（urc）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:related ex:top .\n", ['urc']];
        yield '頂層概念有上位（tchbc）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:narrower ex:top .\nex:top skos:broader ex:a .\n", ['tchbc']];
        yield '詞表沒有頂層概念（otc）' => [$head."ex:s2 a skos:ConceptScheme ; skos:prefLabel \"另一個\"@zh-Hant .\n", ['otc']];
        yield '用了 SKOS 沒有的詞（usr）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant ; skos:prefLable \"甲\"@zh-Hant .\n", ['usr']];
        yield '概念沒有正式名（ml）' => [$head."ex:a a skos:Concept ; skos:altLabel \"甲\"@zh-Hant .\n", ['ml']];
        yield '識別碼不是 http（husv）' => [$head."<urn:x:a> a skos:Concept ; skos:prefLabel \"甲\"@zh-Hant .\n", ['husv']];
        yield '空字串的標籤（el）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"\"@zh-Hant .\n", ['el']];
        yield '零寬空格（ucil）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\\u200B乙\"@zh-Hant .\n", ['ucil']];
        yield '軟連字號（ucil）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\u{00AD}乙\"@zh-Hant .\n", ['ucil']];
        yield '跳脫寫法的控制字元（ucil）' => [$head."ex:a a skos:Concept ; skos:prefLabel \"甲\\u0000乙\"@zh-Hant .\n", ['ucil']];
    }

    /**
     * 有些違規匯出程式本身就不會產生（每個概念只寫一個正式名、兩個方向都寫、沒有外部對應），
     * 用手寫的 Turtle 確認檢查本身抓得到；對照組要是 0，證明不是什麼都報。
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('turtleViolations')]
    #[TestDox('手寫的違規 Turtle 被對應的檢查抓到，乾淨的是 0')]
    public function test_hand_written_violations_are_caught(string $turtle, array $expected): void
    {
        $this->assertSame($expected, $this->failingSkosChecks($turtle));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidSettings(): iterable
    {
        yield '前綴空字串' => ['base_uri', ''];
        yield '前綴沒有 scheme' => ['base_uri', 'example.org/id/'];
        yield '前綴是 ftp' => ['base_uri', 'ftp://example.org/id/'];
        yield '前綴是 javascript' => ['base_uri', 'javascript://example.org/id/'];
        yield '前綴沒有以 / 結尾' => ['base_uri', 'https://example.org/id'];
        yield '前綴沒有主機' => ['base_uri', 'https:///id/'];
        yield '前綴有空白' => ['base_uri', 'https://exa mple.org/id/'];
        yield '前綴有 >' => ['base_uri', 'https://example.org/id>/'];
        yield '前綴有查詢字串' => ['base_uri', 'https://example.org/id?x=/'];
        yield '前綴有 #' => ['base_uri', 'https://example.org/v#/'];
        yield '前綴前後有空白' => ['base_uri', ' https://example.org/id/'];
        yield '前綴是 null' => ['base_uri', null];
        yield '前綴是陣列' => ['base_uri', ['https://example.org/id/']];
        yield '語言空字串' => ['language', ''];
        yield '語言用底線' => ['language', 'zh_Hant'];
        yield '語言結尾是 -' => ['language', 'zh-'];
        yield '語言是中文' => ['language', '中文'];
        yield '語言後面有空白' => ['language', 'zh-Hant '];
        yield '語言是 null' => ['language', null];
        yield '詞表名稱空字串' => ['scheme_label', ''];
        yield '詞表名稱只有空白' => ['scheme_label', " \n\t"];
        yield '詞表名稱是 null' => ['scheme_label', null];
    }

    #[DataProvider('invalidSettings')]
    #[TestDox('設定不合格時丟例外，不產出檔案')]
    public function test_invalid_settings_stop_the_export(string $key, mixed $value): void
    {
        config(["vocabulary.{$key}" => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->exporter()->toTurtle();
    }

    #[TestDox('識別碼照設定的前綴產生；出貨時的佔位值也匯得出來')]
    public function test_identifiers_follow_the_configured_prefix(): void
    {
        $shipped = $this->exporter()->toTurtle();
        $this->assertStringStartsWith(config('vocabulary.base_uri'), $this->exporter()->schemeUri());
        $this->assertSame([], array_filter($this->skosProblems($this->parseTurtle($shipped))));

        config(['vocabulary.base_uri' => 'http://localhost:8000/x/', 'vocabulary.language' => 'en', 'vocabulary.scheme_label' => 'Techniques']);
        $triples = $this->parseTurtle($this->exporter()->toTurtle());

        $this->assertSame('http://localhost:8000/x/vocabulary', $this->exporter()->schemeUri());
        $this->assertSame('http://localhost:8000/x/technique/'.$this->t['PaaS']->id, $this->uri('PaaS'));
        $this->assertSame([['Techniques', 'en']], $this->objects($triples, 'http://localhost:8000/x/vocabulary', 'prefLabel'));
        $this->assertSame([['PaaS', 'en']], $this->objects($triples, $this->uri('PaaS'), 'prefLabel'));
        $this->assertSame([$this->uri('雲端服務')], $this->objects($triples, $this->uri('PaaS'), 'broader'));
    }
}
