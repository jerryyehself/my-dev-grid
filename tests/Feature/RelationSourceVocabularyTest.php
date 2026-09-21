<?php

namespace Tests\Feature;

use App\Models\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 分類帳「述詞的外部詞彙出處」那一列（2026-09-18 記錄）：把 `note` 裡已經寫明的
 * 出處結構化成 `source_vocabulary`/`source_term`，讓「這條述詞是借來的還是
 * 自創的」變成機器可查，而不是只有人讀 note 才看得出來。
 */
class RelationSourceVocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_created_predicates_have_null_source(): void
    {
        // specs/specifiedBy 的 note 明寫「查無乾淨的外部對應」——這是分類帳裡
        // 「對照組排除範圍」的第一條邊界，null 代表這一點，不是欄位還沒填。
        $this->seed();

        foreach (['specs', 'specifiedBy'] as $name) {
            $relation = Relation::where('name', $name)->firstOrFail();
            $this->assertNull($relation->source_vocabulary, "$name 應該是 null（自創）");
            $this->assertNull($relation->source_term, "$name 應該是 null（自創）");
        }
    }

    #[DataProvider('borrowedPredicateProvider')]
    public function test_borrowed_predicates_have_source(string $name, string $vocabulary, string $term): void
    {
        $this->seed();

        $relation = Relation::where('name', $name)->firstOrFail();
        $this->assertSame($vocabulary, $relation->source_vocabulary, "$name 的 source_vocabulary 不符");
        $this->assertSame($term, $relation->source_term, "$name 的 source_term 不符");
    }

    public static function borrowedPredicateProvider(): array
    {
        // 15 筆裡扣掉 specs/specifiedBy（上面那支測試單獨守），剩下 13 筆全部
        // 應該是借來的，逐筆核對——不是只挑幾個代表，因為漏一筆不會報錯，
        // 只會安靜留一個 null。挑選依據記在回填 migration 的 docblock。
        return [
            'documents' => ['documents', 'spdx', 'DOCUMENTATION_OF'],
            'documentedBy（沿用 documents 的詞條，note 沒有另外具名反向詞）' => ['documentedBy', 'spdx', 'DOCUMENTATION_OF'],
            'uses' => ['uses', 'spdx', 'DEPENDS_ON'],
            'used（note 裡跟 uses 同一句具名的反向詞條）' => ['used', 'spdx', 'DEPENDENCY_OF'],
            'requires' => ['requires', 'dcterms', 'requires'],
            'isRequiredBy' => ['isRequiredBy', 'dcterms', 'isRequiredBy'],
            'assists（依語意就近指派，不是 note 逐字具名方向）' => ['assists', 'spdx', 'usesTool'],
            'assisted-by' => ['assisted-by', 'spdx', 'dependsOn'],
            'descendantOf' => ['descendantOf', 'spdx', 'DESCENDANT_OF'],
            'ancestorOf' => ['ancestorOf', 'spdx', 'ANCESTOR_OF'],
            'accompanies' => ['accompanies', 'tillett1987', 'Accompanying'],
            'precedes' => ['precedes', 'tillett1987', 'Sequential'],
            'succeeds（沿用 precedes 的詞條，note 沒有另外具名）' => ['succeeds', 'tillett1987', 'Sequential'],
        ];
    }

    public function test_every_seeded_relation_has_a_source_decision(): void
    {
        // 上面兩支測試合起來要覆蓋全部 15 筆——這支測試不驗證個別值，只驗證
        // 「沒有漏掉的一筆」。漏掉的一筆會落成 source_vocabulary 為 null，
        // 跟「查證後判斷是自創」在資料庫裡長得一模一樣，光看資料分不出來，
        // 所以覆蓋率要用「這 15 個名字都出現在上面兩支測試的清單裡」來守，
        // 不能只信任個別斷言都過了。
        $this->seed();

        $decided = array_merge(
            ['specs', 'specifiedBy'],
            array_column(self::borrowedPredicateProvider(), 0),
        );

        $allNames = Relation::pluck('name')->all();

        $this->assertCount(15, $allNames, '目前 seeder 應該種出 15 筆 relation——這個數字變了，上面兩支測試的覆蓋清單要跟著補');
        $this->assertEqualsCanonicalizing($allNames, $decided, '有 relation 沒有被任何一支測試的清單涵蓋到，即使它剛好是 null 也不能算「驗證過」');
    }

    public function test_backfill_migration_matches_seeder(): void
    {
        // 回填 migration 的 SOURCES 表是手動抄了一份跟 RelationSeeder 一樣的
        // 判斷結果(migration 的 docblock 自己說「是這裡的權威副本」)。兩份
        // 各自維護,以後改其中一份很容易忘記另一份——這支測試直接反射
        // migration 檔案裡的常數,逐筆跟 seeder 種出來的實際資料比對,
        // 抓的正是「改漏一邊」這個具體風險,不是重新驗證數值本身對不對。
        $this->seed();

        $migrationPath = database_path('migrations/2026_09_21_063045_backfill_relation_source_vocabulary_from_notes.php');
        $this->assertFileExists($migrationPath, '回填 migration 的檔名變了——這支測試跟它的路徑是寫死的，要一起改');

        $migration = require $migrationPath;
        $reflection = new \ReflectionClass($migration);
        $sources = $reflection->getConstant('SOURCES');

        $this->assertIsArray($sources);
        $this->assertCount(15, $sources, 'migration 的 SOURCES 表筆數跟 seeder 種出來的筆數對不上');

        foreach ($sources as $name => [$vocabulary, $term]) {
            $relation = Relation::where('name', $name)->first();
            $this->assertNotNull($relation, "migration 的 SOURCES 表裡有 seeder 沒種出來的名字：{$name}");
            // PHP 的雙引號插值對變數名稱後面緊接非 ASCII 位元組會誤判（合法變數名的位元組
            // 範圍其實含 0x80-0xff，是為了相容舊版 8-bit 編碼，UTF-8 續位元組剛好落在
            // 這個範圍），這裡跟下面三處都要用 {$var} 明確界定邊界，不能只加空格繞過——
            // 加空格在這幾行看起來會動到訊息措辭，{$var} 才是不改文字本身的正確修法。
            $this->assertSame($vocabulary, $relation->source_vocabulary, "{$name}：migration 跟 seeder 的 source_vocabulary 不一致");
            $this->assertSame($term, $relation->source_term, "{$name}：migration 跟 seeder 的 source_term 不一致");
        }
    }

    public function test_reverse_pairs_agree_on_shared_source(): void
    {
        // documentedBy／used／succeeds 這三筆沒有在自己的 note 裡另外具名詞條，
        // 沿用正向那一筆的判斷——這支測試守的是「兩邊沒有因為之後有人改了
        // 其中一筆就悄悄長出分歧」，不是重新驗證值本身（上面已經驗證過）。
        $this->seed();

        $pairs = [
            ['documents', 'documentedBy'],
            ['uses', 'used'],
            ['precedes', 'succeeds'],
        ];

        foreach ($pairs as [$forward, $reverse]) {
            $forwardRelation = Relation::where('name', $forward)->firstOrFail();
            $reverseRelation = Relation::where('name', $reverse)->firstOrFail();

            // uses/used 的詞條實際上不同（DEPENDS_ON vs DEPENDENCY_OF），
            // 只有 vocabulary 該一致；documents/documentedBy 與
            // precedes/succeeds 才是 vocabulary 跟 term 都完全沿用。
            $this->assertSame(
                $forwardRelation->source_vocabulary,
                $reverseRelation->source_vocabulary,
                "{$forward}／{$reverse} 的 source_vocabulary 應該一致",
            );
        }

        foreach ([['documents', 'documentedBy'], ['precedes', 'succeeds']] as [$forward, $reverse]) {
            $forwardRelation = Relation::where('name', $forward)->firstOrFail();
            $reverseRelation = Relation::where('name', $reverse)->firstOrFail();

            $this->assertSame(
                $forwardRelation->source_term,
                $reverseRelation->source_term,
                "{$forward}／{$reverse} 沒有各自具名的反向詞條，source_term 應該完全相同",
            );
        }
    }
}
