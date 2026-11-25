<?php

namespace Database\Seeders;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class RelationSeeder extends Seeder
{
    public function run(): void
    {

        // 第五、六個元素是 source_vocabulary／source_term(分類帳「述詞的外部詞彙
        // 出處」那一列,2026-09-21 結構化)。null 代表自創,理由跟每筆詞條的挑選
        // 邏輯記在回填 migration
        // (backfill_relation_source_vocabulary_from_notes.php)的 docblock,
        // 這裡不重複貼一次——那支 migration 的 SOURCES 表是當時的快照,
        // 之後改過的詞條由後續的 migration 疊上去,兩邊要保持一致(有測試守著)。
        $seeds = [
            ['00', '10', 'specs', 'No clean external-vocabulary mapping; closest is Dublin Core dcterms:conformsTo, but direction/semantics don\'t fully align — kept as a project-specific predicate (Documentation specs a Technique).', null, null],
            ['00', '20', 'documents', 'SPDX DOCUMENTATION_OF — Documentation documents an Implementation (SPDX 2.3 relationships spec).', 'spdx', 'DOCUMENTATION_OF'],
            ['10', '00', 'specifiedBy', 'Reverse of specs.', null, null],
            // uses／usedBy：實作 uses 技術（Implementation 當主詞），跟 ER model 概念圖一致。2026-09-30
            // 以前這一對定義反了（Technique uses Implementation），改名的經過與理由見
            // 2026_09_30_180000_fix_uses_relation_direction.php。這一列留在原本的位置，
            // 種出來的 id 才會跟改名過的舊資料庫一樣（technique_implementation 的邊都指向這一筆）。
            ['10', '20', 'usedBy', 'Reverse of uses.', 'spdx', 'DEPENDENCY_OF'],
            ['20', '00', 'documentedBy', 'Reverse of documents.', 'spdx', 'DOCUMENTATION_OF'],
            ['20', '10', 'uses', 'SPDX 2.3 DEPENDS_ON (A depends on B) — an Implementation uses a Technique. Close in spirit to PROV-O prov:used (Activity used Entity).', 'spdx', 'DEPENDS_ON'],
        ];

        $reversePairs = [
            ['specs', 'specifiedBy'],
            ['documents', 'documentedBy'],
            ['usedBy', 'uses'],
        ];

        // 可重複執行（2026-10-02）：每一筆都經過 relation()，以 name 為鍵「沒有才建」，
        // 反向配對只在這次有新建的時候設（見 pairReverse()）。原本全部用 create()，
        // 正式環境跑第二次就整份重複。部署時會自動跑（ReferenceDataSeeder），所以重跑
        // 不能動到後台改過的任何東西。

        // 一次撈出所有 scopes 並依 is_scope_lead 分組
        $scopes = Scope::all();

        $leadScopes = $scopes->whereNull('parent_class');     // 沒有上層的
        // $nonLeadScopes = $scopes->whereNotNull('parent_class');

        // 建立固定關聯資料
        $seeded = [];
        foreach ($seeds as [$from, $to, $name, $note, $sourceVocabulary, $sourceTerm]) {

            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            $seeded[$name] = $this->relation([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'name' => $name,
                'note' => $note,
                'source_vocabulary' => $sourceVocabulary,
                'source_term' => $sourceTerm,
            ]);
        }

        foreach ($reversePairs as [$name, $reverse]) {
            $this->pairReverse($seeded[$name], $seeded[$reverse]);
        }

        // 「assisted-by」/「assists」跟「uses」/「usedBy」同一組 class_number，
        // 差別在 AI 只提供建議(assisted-by/assists)還是直接產出/執行成品(uses/usedBy)。
        //
        // 2026-09-12 修正：這兩組原本用 parent_class 把 assists/assisted-by
        // 掛成 uses/usedBy 的子關係，是誤用——assists 的定義明講「非直接產出/
        // 執行成品」，明確排除了 uses 的情況，依 RDFS rdfs7（子屬性的每一筆
        // 事實都蘊含父屬性成立），assists ⊑ uses 會導出自相矛盾的蘊含。兩者
        // 該是平輩（互斥），不是父子——比照 SPDX 3.0.1 RelationshipType 把
        // usesTool／dependsOn 並列為平輩的先例（class_number 相同、call_number
        // 不同就足夠表達「同一組裡的另一個關係」，不需要再疊加 parent_class）。
        // source_term：assisted-by（實作 → AI 工具）對應 SPDX 3.0.1 usesTool（「from 把 to
        // 當工具用」，from 是實作），assists 是反向、SPDX 3 沒有反向詞條，照 documentedBy
        // 的慣例沿用 usesTool。2026-09-30 以前 assisted-by 對到 dependsOn——那是 uses 的
        // 詞條，跟上面「與 uses 平輩」自相矛盾，修正經過見
        // 2026_09_30_180000_fix_uses_relation_direction.php。
        $childSeeds = [
            ['20', '10', 'assisted-by', 'usesTool'],
            ['10', '20', 'assists', 'usesTool'],
        ];

        foreach ($childSeeds as [$from, $to, $name, $sourceTerm]) {
            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            $seeded[$name] = $this->relation([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'call_number' => '10',
                'name' => $name,
                'note' => 'AI 輔助但非直接產出/執行成品；與 uses/usedBy 平輩，不是子關係（比照 SPDX usesTool/dependsOn 的平輩先例）。',
                'source_vocabulary' => 'spdx',
                'source_term' => $sourceTerm,
            ]);
        }

        $this->pairReverse($seeded['assisted-by'], $seeded['assists']);

        // dcterms:requires／dcterms:isRequiredBy（Dublin Core）——同類型的依賴，
        // 透過 entity_relations 使用（例如某個 Technique requires 另一個 Technique）。
        // 之前由 backend 提出但延後處理。entity_relations 不會交叉檢查 Relation 的
        // subject_id／object_id 與它所連結的實體是否一致（見
        // EntityRelation::assertValidEntityReferences——它只檢查 entity_type，
        // 以及兩個 id 是否都存在於該實體的資料表中），因此一筆通用的自我配對 Relation
        // 就能涵蓋所有 entity_type；
        // 選用 Technique 作為代表性的 subject／object scope，因為 class_number 11（Technique 自身）
        // 在上面的種子謂詞（01／02／10／12／20／21）中沒有被使用。
        $technique = $leadScopes->firstWhere('class_number', '10');

        $requires = $this->relation([
            'subject_id' => $technique->id,
            'object_id' => $technique->id,
            'class_number' => '11',
            'call_number' => '00',
            'name' => 'requires',
            'note' => 'dcterms:requires — same-type dependency (e.g. a Technique that requires another Technique).',
            'source_vocabulary' => 'dcterms',
            'source_term' => 'requires',
        ]);

        // isRequiredBy 是 requires 的反向關係，反向關係用 reverse_id 表達
        // （見下一行），不疊加 parent_class——DCMI Metadata Terms 的
        // dcterms:requires／dcterms:isRequiredBy 皆為 dcterms:relation 的
        // 子屬性、彼此平輩，沒有互為父子，這裡比照同一個先例。
        $isRequiredBy = $this->relation([
            'subject_id' => $technique->id,
            'object_id' => $technique->id,
            'class_number' => '11',
            'call_number' => '10',
            'name' => 'isRequiredBy',
            'note' => 'dcterms:isRequiredBy — reverse of requires.',
            'source_vocabulary' => 'dcterms',
            'source_term' => 'isRequiredBy',
        ]);

        $this->pairReverse($requires, $isRequiredBy);

        // Implementation×Implementation 同型別關聯（class_number '22'，跟上面
        // requires/isRequiredBy 用 Technique×Technique '11' 同一個「一組
        // 通用 subject/object 代表這個 entity_type 的所有列」手法——
        // EntityRelation::assertValidEntityReferences 不會拿 Relation 自己的
        // subject_id/object_id 去比對實際連結的 entity id，只檢查 entity_type
        // 本身，所以這裡同樣只需要一組代表性的 Scope）。
        $implementation = $leadScopes->firstWhere('class_number', '20');

        // 2026-09-11 使用者確認：GitHub repo 之間「這個是從那個衍生出來的」
        // 這種真實已知關係（含 fork——fork 本質上就是這個關係的特例，不另立
        // predicate）。SPDX 2.3 relationships 定義的 DESCENDANT_OF／
        // ANCESTOR_OF：「同一譜系，但晚於／早於」。
        // https://spdx.github.io/spdx-spec/v3.0.1/model/Core/Vocabularies/RelationshipType/
        $descendantOf = $this->relation([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '00',
            'name' => 'descendantOf',
            'note' => 'SPDX relationship type DESCENDANT_OF — "same lineage but post-dates". 涵蓋 GitHub repo 的「衍生自」與「fork 自」，fork 是這個關係的特例，不另立 predicate。',
            'source_vocabulary' => 'spdx',
            'source_term' => 'DESCENDANT_OF',
        ]);

        $ancestorOf = $this->relation([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '10',
            'name' => 'ancestorOf',
            'note' => 'SPDX relationship type ANCESTOR_OF — reverse of descendantOf.',
            'source_vocabulary' => 'spdx',
            'source_term' => 'ANCESTOR_OF',
        ]);

        $this->pairReverse($descendantOf, $ancestorOf);

        // 2026-09-11 使用者確認：兩個 repo 共同組成同一個產品（例如前後端
        // 拆分），彼此獨立但相伴而生，不是誰包含誰的 whole-part。外部詞彙取 RDA 的
        // 「is complemented by work」（P10103）：兩個 work 配成一組、沒有哪一個是主要的，
        // 跟「對等、相伴」的語意相符。對稱關係，reverse_id 指向自己。
        // 已存在的資料庫由 2026_11_24_000002_map_implementation_relations_to_rda_and_bibframe.php 改值。
        $accompanies = $this->relation([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '20',
            'name' => 'accompanies',
            'note' => 'RDA P10103 (is complemented by work), http://rdaregistry.info/Elements/w/P10103 — "Relates a work to a work that is paired with a related work without either work being considered to be predominant." 對稱關係：兩個獨立但相伴而生、共同組成同一個產品的 repo（例如前後端拆分）。',
            'source_vocabulary' => 'rda',
            'source_term' => 'P10103',
        ]);
        $this->pairReverse($accompanies, $accompanies);

        // 2026-09-11 使用者確認：同性質的練習專案，依建立時間前後相接
        // （啟發式：同樣的技術主題 + 建立時間連續，中間沒有夾著其他主題的
        // 專案，且間隔在 1 年以內）。外部詞彙取 BIBFRAME 的 succeededBy／precededBy。
        // 注意方向：「A precedes B」就是「A bf:succeededBy B」，所以 precedes 對到 succeededBy，
        // succeeds 對到 precededBy，不要照字面對反。
        $precedes = $this->relation([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '30',
            'name' => 'precedes',
            'note' => 'BIBFRAME succeededBy, http://id.loc.gov/ontologies/bibframe/succeededBy — "Resource that succeeds the resource being described, e.g., later in time or after in a narrative". A precedes B 即 A bf:succeededBy B。依建立時間前後相接的同性質練習專案。',
            'source_vocabulary' => 'bibframe',
            'source_term' => 'succeededBy',
        ]);

        $succeeds = $this->relation([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '40',
            'name' => 'succeeds',
            'note' => 'BIBFRAME precededBy, http://id.loc.gov/ontologies/bibframe/precededBy — "Resource that precedes the resource being described, e.g., is earlier in time or before in narrative". Reverse of precedes：A succeeds B 即 A bf:precededBy B。',
            'source_vocabulary' => 'bibframe',
            'source_term' => 'precededBy',
        ]);

        $this->pairReverse($precedes, $succeeds);

        // 2026-09-30 使用者同意：技術的版本各自是一筆 Technique（title 相同、version 填主版號），
        // 用 dcterms:isVersionOf／hasVersion 連回版本留空的那一筆。一個專案因此可以同時連到
        // Vue 2 和 Vue 3，升級的歷史不會被蓋掉。定義跟理由的權威副本在
        // 2026_09_30_190100_add_technique_version_relations.php；放在最後面，種出來的 id
        // 才會跟那支 migration 補進舊資料庫的一樣。
        $versionRelations = [];
        foreach ((require database_path('migrations/2026_09_30_190100_add_technique_version_relations.php'))::RELATIONS as $name => $definition) {
            $versionRelations[$name] = $this->relation([
                'subject_id' => $technique->id,
                'object_id' => $technique->id,
                'class_number' => '11',
                'call_number' => $definition['call_number'],
                'name' => $name,
                'note' => $definition['note'],
                'source_vocabulary' => 'dcterms',
                'source_term' => $definition['source_term'],
            ]);
        }
        // 跟上面其他述詞一樣經過 relation()／pairReverse()，重跑不會重複建、也不會蓋掉後台改過的配對（#84）
        $this->pairReverse($versionRelations['isVersionOf'], $versionRelations['hasVersion']);

        // 文章在 front matter 宣告「這篇在講哪些技術、哪些專案」，匯入時建成連結
        // （ArticleSourceService）。借 schema.org 的 about／subjectOf：
        // https://schema.org/about 、https://schema.org/subjectOf 。
        //
        // 技術和專案各一對，因為述詞的受詞只能指到一個頂層分類（連結的分類檢查靠它，
        // RelationFitsLinkedScopes），一對 about 沒辦法同時涵蓋 Technique 和 Implementation；
        // 名稱又不能重複，專案那一對加上 Project 字樣。兩對都對應同一個 schema.org 詞條。
        // 跟 specs／documents 平輩（類號相同、子類號不同），不是子關係：說明一篇文章講到某個
        // 主題，不蘊含它是那個主題的規格或文件。放在最後面，原有述詞的 id 不變。
        $documentation = $leadScopes->firstWhere('class_number', '00');

        $aboutSeeds = [
            // [名稱, 反向名稱, 受詞端的頂層分類, 類號, 說明]
            ['about', 'subjectOf', $technique, '01', 'Documentation is about a Technique'],
            ['aboutProject', 'projectSubjectOf', $implementation, '02', 'Documentation is about an Implementation (project)'],
        ];

        foreach ($aboutSeeds as [$name, $reverseName, $object, $classNumber, $meaning]) {
            $about = $this->relation([
                'subject_id' => $documentation->id,
                'object_id' => $object->id,
                'class_number' => $classNumber,
                'call_number' => '10',
                'name' => $name,
                'note' => "schema.org about (https://schema.org/about) — {$meaning}: the article discusses it, without being its spec or documentation.",
                'source_vocabulary' => 'schema',
                'source_term' => 'about',
            ]);

            $subjectOf = $this->relation([
                'subject_id' => $object->id,
                'object_id' => $documentation->id,
                'class_number' => $classNumber[1].$classNumber[0],
                'call_number' => '10',
                'name' => $reverseName,
                'note' => 'schema.org subjectOf (https://schema.org/subjectOf) — reverse of '.$name.'.',
                'source_vocabulary' => 'schema',
                'source_term' => 'subjectOf',
            ]);

            $this->pairReverse($about, $subjectOf);
        }

        // 外部參考（外部文章、repo、skill）還需要兩組述詞，主詞都是 Documentation 或 Implementation。
        // 類號照其他述詞的規則：主詞頂層分類的第一碼＋受詞頂層分類的第一碼（文件→技術 01、文件→實作 02，
        // 反向 10、20）。子類號接在該類號已用過的後面：01 已用 00（specs）、10（about），02 已用 00（documents）、
        // 10（aboutProject），反向的 10 與 20 同理，所以兩組都用 20。放在最後面，原有述詞的 id 不變。
        //
        // - targets／targetedBy：這份來源（skill、外掛、工具）是給這個技術（通常是 AI 工具）用的。
        //   Schema.org targetProduct；Schema.org 沒有反向詞條，照 assists／documentedBy 的先例沿用同一個詞條。
        // - influences／influencedBy：這份來源的規則、做法或概念已經寫進這個實作。只有借來的東西真的進了
        //   那個實作才連，還在計畫中的不連。W3C PROV-O prov:influenced／prov:wasInfluencedBy。
        $documentationPredicates = [
            ['01', 'targets', 'schema.org targetProduct — this Documentation (a skill, plugin or tool) is written for that Technique, usually an AI tool such as Claude Code.', 'schema', 'targetProduct'],
            ['10', 'targetedBy', 'Reverse of targets. schema.org has no inverse term, so it reuses targetProduct as assists/documentedBy do.', 'schema', 'targetProduct'],
            ['02', 'influences', 'W3C PROV-O prov:influenced — this Documentation\'s rules, practices or ideas have been adopted into the Implementation. Link only once the adoption has landed in that Implementation, not while it is still planned.', 'prov', 'influenced'],
            ['20', 'influencedBy', 'W3C PROV-O prov:wasInfluencedBy — reverse of influences.', 'prov', 'wasInfluencedBy'],
        ];

        foreach ($documentationPredicates as [$classNumber, $name, $note, $sourceVocabulary, $sourceTerm]) {
            // 主詞、受詞的頂層分類由類號的兩碼決定：第一碼是主詞、第二碼是受詞的頂層類號（"{碼}0"）。
            // 反向述詞（類號 10、20）的主詞受詞剛好對調，所以直接用類號查，不另外寫一份對照。
            $subject = $leadScopes->firstWhere('class_number', "{$classNumber[0]}0");
            $object = $leadScopes->firstWhere('class_number', "{$classNumber[1]}0");

            $seeded[$name] = $this->relation([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $classNumber,
                'call_number' => '20',
                'name' => $name,
                'note' => $note,
                'source_vocabulary' => $sourceVocabulary,
                'source_term' => $sourceTerm,
            ]);
        }

        $this->pairReverse($seeded['targets'], $seeded['targetedBy']);
        $this->pairReverse($seeded['influences'], $seeded['influencedBy']);

        // 改作與建置產物：這個實作是從另一份來源改出來的，或是由另一個專案建置出來的成品。
        // 借 schema.org 的 isBasedOn／isBasisFor：
        // https://schema.org/isBasedOn 、https://schema.org/isBasisFor 。
        //
        // 述詞的受詞只能指到一個頂層分類（RelationFitsLinkedScopes），名稱又不能重複，所以來源是
        // 文件（例如外部 repo 的 Documentation）和來源是另一個實作各一對，後者加上 Project 字樣，
        // 做法跟 about／aboutProject 一樣。兩對都對應同一組 schema.org 詞條。
        // - 文件 isBasisFor 實作：外部 repo 是這個實作的基礎（改作）。類號 02／20，
        //   子類號接在該類號已用過的 00、10、20 後面，所以用 30。
        // - 實作 isBasedOnProject 實作：建置產物指向它的來源專案。類號 22，已用過 00 到 40，所以用 50、60。
        //   跟 descendantOf 不同：descendantOf 是同一個譜系的前後代（含 fork），這一對是產物與來源。
        // 放在最後面，原有述詞的 id 不變。
        $derivationPredicates = [
            // [類號, 子類號, 名稱, 說明, 詞條]
            ['02', '30', 'isBasisFor', 'this Documentation (an external repository) is the basis the Implementation was adapted from.', 'isBasisFor'],
            ['20', '30', 'isBasedOn', 'reverse of isBasisFor: the Implementation is adapted from this Documentation (an external repository).', 'isBasedOn'],
            ['22', '50', 'isBasedOnProject', 'the Implementation is a build output of, or adapted from, another Implementation (the source project). Differs from descendantOf, which links two repos of the same lineage.', 'isBasedOn'],
            ['22', '60', 'isBasisForProject', 'reverse of isBasedOnProject: this Implementation is the source of another Implementation\'s build output.', 'isBasisFor'],
        ];

        foreach ($derivationPredicates as [$classNumber, $callNumber, $name, $note, $sourceTerm]) {
            // 主詞、受詞的頂層分類由類號的兩碼決定，做法跟上面外部參考的述詞一樣
            $subject = $leadScopes->firstWhere('class_number', "{$classNumber[0]}0");
            $object = $leadScopes->firstWhere('class_number', "{$classNumber[1]}0");

            $seeded[$name] = $this->relation([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $classNumber,
                'call_number' => $callNumber,
                'name' => $name,
                'note' => "schema.org {$sourceTerm} (https://schema.org/{$sourceTerm}) — {$note}",
                'source_vocabulary' => 'schema',
                'source_term' => $sourceTerm,
            ]);
        }

        $this->pairReverse($seeded['isBasisFor'], $seeded['isBasedOn']);
        $this->pairReverse($seeded['isBasedOnProject'], $seeded['isBasisForProject']);

        // 專案「關於」某個技術：GitHub 同步只在 topic 看到、程式裡（語言統計、套件清單）沒有證據的技術，
        // 寫這一對，不寫 usedBy（見 SaveReposDataService）。借 schema.org 的 about／subjectOf。
        //
        // technique_implementation 一律以技術當主詞，同步寫的是 subjectOfProject（技術 → 專案），
        // projectAbout 是反向，給圖上反著讀用。about／subjectOf 已經給「文件 ↔ 技術」用了、
        // aboutProject／projectSubjectOf 已經給「文件 ↔ 專案」用了，名稱不能重複，所以在專案那一端加 Project 字樣。
        // 跟 usedBy／uses、assists／assisted-by 平輩（類號 12／21 相同、子類號不同）：說一個專案在講某個技術，
        // 不蘊含它用了那個技術。12 已用 00（usedBy）、10（assists），21 已用 00（uses）、10（assisted-by），所以用 20。
        // 放在最後面，原有述詞的 id 不變。
        $projectAboutPredicates = [
            // [類號, 名稱, 主詞端, 受詞端, 詞條, 說明]
            ['12', 'subjectOfProject', $technique, $implementation, 'subjectOf', 'the Technique is a subject of the Implementation (project): the project is about it, but its code shows no evidence of using it (it appears only in the repository topics).'],
            ['21', 'projectAbout', $implementation, $technique, 'about', 'reverse of subjectOfProject: the Implementation (project) is about the Technique without using it in its code.'],
        ];

        foreach ($projectAboutPredicates as [$classNumber, $name, $subject, $object, $sourceTerm, $note]) {
            $seeded[$name] = $this->relation([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $classNumber,
                'call_number' => '20',
                'name' => $name,
                'note' => "schema.org {$sourceTerm} (https://schema.org/{$sourceTerm}) — {$note}",
                'source_vocabulary' => 'schema',
                'source_term' => $sourceTerm,
            ]);
        }

        $this->pairReverse($seeded['subjectOfProject'], $seeded['projectAbout']);

        // 詞表的上下位與相關，借 SKOS 的 broader／narrower／related：
        // https://www.w3.org/TR/skos-reference/#semantic-relations
        // 上下位與相關的主詞、受詞都是 Technique，所以跟 requires、isVersionOf 一樣
        // 用 Technique×Technique 的類號 11，子類號接在 hasVersion（30）後面。broader／narrower 互為反向；
        // related 是對稱關係，反向指向自己（跟 accompanies 一樣）。邊只存一個方向：上下位存 broader
        // （下位指向上位），narrower 是反向走時用的名稱。放在最後面，既有述詞種出來的 id 不變。
        $skosSeeds = [
            ['40', 'broader', 'SKOS skos:broader — the object Technique is a broader concept than the subject (e.g. Cloudflare Workers broader Cloudflare).'],
            ['50', 'narrower', 'SKOS skos:narrower — reverse of broader.'],
            ['60', 'related', 'SKOS skos:related — associative, symmetric link between two Techniques that are neither broader nor narrower than each other (e.g. vue-router related SPA).'],
        ];
        foreach ($skosSeeds as [$callNumber, $name, $note]) {
            $seeded[$name] = $this->relation([
                'subject_id' => $technique->id,
                'object_id' => $technique->id,
                'class_number' => '11',
                'call_number' => $callNumber,
                'name' => $name,
                'note' => $note,
                'source_vocabulary' => 'skos',
                'source_term' => $name,
            ]);
        }
        $this->pairReverse($seeded['broader'], $seeded['narrower']);
        $this->pairReverse($seeded['related'], $seeded['related']);

        // $this->createRandomRelation($nonLeadScopes);
    }

    /**
     * 以 name 為鍵「沒有才建」：已經存在的不更新，重跑不會重複也不會改動資料。
     *
     * - 鍵用 name：程式裡找 relation 一律用 name（SaveReposDataService、回填
     *   migration），資料表沒有 unique 約束可以依靠。
     * - 不用 updateOrCreate：述詞在後台可以編輯（D-81）；而且已被引用的
     *   relation 改到 LOCKED_FIELDS 會丟 RelationLockedException，重跑 seeder
     *   不該碰既有資料。要改既有資料，請走後台或另寫 migration。
     * - 含軟刪除的列（withTrashed）：後台刪掉的述詞，重跑不會又建一筆新的回來。
     */
    private function relation(array $attributes): Relation
    {
        return Relation::withTrashed()->firstOrCreate(['name' => $attributes['name']], $attributes);
    }

    /**
     * 設反向關係（對稱關係傳同一筆兩次），另一邊由 Relation::syncReverse()（saved 事件）
     * 自動補成雙向。三個條件都成立才設：
     *
     * - 至少一邊是這次新建的：兩邊都早就存在，代表配對狀態是之前定下來的，包括在後台
     *   取消的配對（反向關係清成 null），重跑不能把它配回去。
     * - 兩邊都沒被刪除：不把新建的述詞配給後台刪掉的那一筆。
     * - 兩邊都還沒配對：不搶走別筆的反向關係。
     * - 兩邊都沒有棄用：成對的兩邊棄用狀態要一致（見 Relation::deprecate()），新建的述詞不能配給
     *   一條已棄用的。這種情況要在後台處理（取消棄用、或另指定反向）。
     */
    private function pairReverse(Relation $relation, Relation $reverse): void
    {
        if (! $relation->wasRecentlyCreated && ! $reverse->wasRecentlyCreated) {
            return;
        }

        if ($relation->trashed() || $reverse->trashed()) {
            return;
        }

        if ($relation->isDeprecated() || $reverse->isDeprecated()) {
            return;
        }

        if (is_null($relation->reverse_id) && is_null($reverse->reverse_id)) {
            $relation->update(['reverse_id' => $reverse->id]);
        }
    }

    private function createRandomRelation($nonLeadScopes)
    {
        // 建立非主類之間的隨機關聯
        if ($nonLeadScopes->count() >= 2) {
            $relations = collect();
            $serials = [];
            $targetCount = ($nonLeadScopes->count() * ($nonLeadScopes->count() - 1)) / 2;

            while ($relations->count() < $targetCount) {
                $subject = $nonLeadScopes->random();
                $object = $nonLeadScopes->where('id', '!=', $subject->id)->random();

                $classNumber = str($subject->class_number)[0].str($object->class_number)[0];
                $serials[$classNumber] = ! isset($serials[$classNumber]) ? 1 : ++$serials[$classNumber];

                $callNumber = str_pad($serials[$classNumber], 2, '0', STR_PAD_LEFT);

                $parent = null;
                if ($callNumber != '00') {
                    $parent = Relation::where('class_number', $classNumber)
                        ->where('call_number', '00')
                        ->value('id');
                }

                $relation = Relation::factory()->make([
                    'subject_id' => $subject->id,
                    'object_id' => $object->id,
                    'parent_class' => $parent,
                    'class_number' => $classNumber,
                    'call_number' => $callNumber,
                ]);

                $relationData = Arr::except($relation->attributesToArray(), ['ReferenceCode']);

                $relationData['created_at'] = now()->format('Y-m-d H:i:s');
                $relationData['updated_at'] = now()->format('Y-m-d H:i:s');

                $relations->add($relationData);
            }

            Relation::insert($relations->values()->toArray());
        }
    }
}
