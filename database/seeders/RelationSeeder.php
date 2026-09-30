<?php

namespace Database\Seeders;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class RelationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // 第五、六個元素是 source_vocabulary／source_term(分類帳「述詞的外部詞彙
        // 出處」那一列,2026-09-21 結構化)。null 代表自創,理由跟每筆詞條的挑選
        // 邏輯記在回填 migration
        // (backfill_relation_source_vocabulary_from_notes.php)的 docblock,
        // 這裡不重複貼一次——那支 migration 的 SOURCES 表是這裡的權威副本,
        // 兩邊要保持一致(有測試守著)。
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

        $reverseSeed = [
            ['specs', 'specifiedBy'],
            ['documents', 'documentedBy'],
            ['specifiedBy', 'specs'],
            ['usedBy', 'uses'],
            ['documentedBy', 'documents'],
            ['uses', 'usedBy'],
        ];

        // 一次撈出所有 scopes 並依 is_scope_lead 分組
        $scopes = Scope::all();

        $leadScopes = $scopes->whereNull('parent_class');     // 沒有上層的
        // $nonLeadScopes = $scopes->whereNotNull('parent_class');

        // 建立固定關聯資料
        foreach ($seeds as [$from, $to, $name, $note, $sourceVocabulary, $sourceTerm]) {

            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            Relation::create([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'name' => $name,
                'note' => $note,
                'source_vocabulary' => $sourceVocabulary,
                'source_term' => $sourceTerm,
            ]);
        }

        foreach ($reverseSeed as [$subject, $reverse]) {
            $reverseId = Relation::where('name', $reverse)->value('id');
            Relation::where('name', $subject)->update(['reverse_id' => $reverseId]);
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

            Relation::create([
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

        $reverseId = Relation::where('name', 'assists')->value('id');
        Relation::where('name', 'assisted-by')->update(['reverse_id' => $reverseId]);
        $reverseId = Relation::where('name', 'assisted-by')->value('id');
        Relation::where('name', 'assists')->update(['reverse_id' => $reverseId]);

        // dcterms:requires / dcterms:isRequiredBy (Dublin Core) — same-type
        // dependency, used via entity_relations (e.g. a Technique that
        // requires another Technique). Previously proposed and deferred
        // by backend. entity_relations doesn't cross-check a Relation's
        // own subject_id/object_id against the entities it links (see
        // EntityRelation::assertValidEntityReferences — it only checks
        // entity_type and that both ids exist in that entity's table), so
        // one generic self-paired Relation covers every entity_type;
        // Technique is picked as the representative subject/object scope
        // since class_number 11 (Technique-self) is otherwise unused by
        // the seeded predicates above (01/02/10/12/20/21).
        $technique = $leadScopes->firstWhere('class_number', '10');

        $requires = Relation::create([
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
        $isRequiredBy = Relation::create([
            'subject_id' => $technique->id,
            'object_id' => $technique->id,
            'class_number' => '11',
            'call_number' => '10',
            'name' => 'isRequiredBy',
            'note' => 'dcterms:isRequiredBy — reverse of requires.',
            'source_vocabulary' => 'dcterms',
            'source_term' => 'isRequiredBy',
        ]);

        $requires->update(['reverse_id' => $isRequiredBy->id]);
        $isRequiredBy->update(['reverse_id' => $requires->id]);

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
        // ANCESTOR_OF：「same lineage but post-dates／pre-dates」。
        // https://spdx.github.io/spdx-spec/v3.0.1/model/Core/Vocabularies/RelationshipType/
        $descendantOf = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '00',
            'name' => 'descendantOf',
            'note' => 'SPDX relationship type DESCENDANT_OF — "same lineage but post-dates". 涵蓋 GitHub repo 的「衍生自」與「fork 自」，fork 是這個關係的特例，不另立 predicate。',
            'source_vocabulary' => 'spdx',
            'source_term' => 'DESCENDANT_OF',
        ]);

        $ancestorOf = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '10',
            'name' => 'ancestorOf',
            'note' => 'SPDX relationship type ANCESTOR_OF — reverse of descendantOf.',
            'source_vocabulary' => 'spdx',
            'source_term' => 'ANCESTOR_OF',
        ]);

        $descendantOf->update(['reverse_id' => $ancestorOf->id]);
        $ancestorOf->update(['reverse_id' => $descendantOf->id]);

        // 2026-09-11 使用者確認：兩個 repo 共同組成同一個產品（例如前後端
        // 拆分），彼此獨立但相伴而生，不是誰包含誰的 whole-part。取自 Tillett
        // (1987) 書目關係分類法的 Accompanying 類別命名——老實記錄：這不是
        // DCMI/SPDX 那種有固定 predicate URI 的機器可讀詞彙，是分類法的類別
        // 名稱，沒有更貼切的正式詞彙可用。對稱關係，reverse_id 指向自己。
        $accompanies = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '20',
            'name' => 'accompanies',
            'note' => 'Tillett (1987) bibliographic-relationships taxonomy 的 Accompanying 類別命名（非 DCMI/SPDX 正式詞彙）。對稱關係：兩個獨立但相伴而生、共同組成同一個產品的 repo（例如前後端拆分）。',
            'source_vocabulary' => 'tillett1987',
            'source_term' => 'Accompanying',
        ]);
        $accompanies->update(['reverse_id' => $accompanies->id]);

        // 2026-09-11 使用者確認：同性質的練習專案，依建立時間前後相接
        // （啟發式：同樣的技術主題 + 建立時間連續，中間沒有夾著其他主題的
        // 專案，且間隔在 1 年以內）。同樣取自 Tillett (1987) 的 Sequential
        // 類別命名，非正式機器可讀詞彙——老實記錄。
        $precedes = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '30',
            'name' => 'precedes',
            'note' => 'Tillett (1987) bibliographic-relationships taxonomy 的 Sequential 類別命名（非 DCMI/SPDX 正式詞彙）。依建立時間前後相接的同性質練習專案。',
            'source_vocabulary' => 'tillett1987',
            'source_term' => 'Sequential',
        ]);

        $succeeds = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '40',
            'name' => 'succeeds',
            'note' => 'Reverse of precedes.',
            'source_vocabulary' => 'tillett1987',
            'source_term' => 'Sequential',
        ]);

        $precedes->update(['reverse_id' => $succeeds->id]);
        $succeeds->update(['reverse_id' => $precedes->id]);

        // 2026-09-30 使用者同意：技術的版本各自是一筆 Technique（title 相同、version 填主版號），
        // 用 dcterms:isVersionOf／hasVersion 連回版本留空的那一筆。一個專案因此可以同時連到
        // Vue 2 和 Vue 3，升級的歷史不會被蓋掉。定義跟理由的權威副本在
        // 2026_09_30_190100_add_technique_version_relations.php；放在最後面，種出來的 id
        // 才會跟那支 migration 補進舊資料庫的一樣。
        $versionRelations = [];
        foreach ((require database_path('migrations/2026_09_30_190100_add_technique_version_relations.php'))::RELATIONS as $name => $definition) {
            $versionRelations[$name] = Relation::create([
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
        $versionRelations['isVersionOf']->update(['reverse_id' => $versionRelations['hasVersion']->id]);
        $versionRelations['hasVersion']->update(['reverse_id' => $versionRelations['isVersionOf']->id]);

        // $this->createRandomRelation($nonLeadScopes);
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
