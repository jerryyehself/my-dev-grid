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

        $seeds = [
            ['00', '10', 'specs', 'No clean external-vocabulary mapping; closest is Dublin Core dcterms:conformsTo, but direction/semantics don\'t fully align — kept as a project-specific predicate (Documentation specs a Technique).'],
            ['00', '20', 'documents', 'SPDX DOCUMENTATION_OF — Documentation documents an Implementation (SPDX 2.3 relationships spec).'],
            ['10', '00', 'specifiedBy', 'Reverse of specs.'],
            ['10', '20', 'uses', 'Close in spirit to SPDX DEPENDS_ON/DEPENDENCY_OF and PROV-O prov:used (Activity used Entity); this project\'s subject direction (Technique as subject) is a project convention, not a literal PROV-O mapping — kept as-is since data already exists.'],
            ['20', '00', 'documentedBy', 'Reverse of documents.'],
            ['20', '10', 'used', 'Reverse of uses.'],
        ];

        $reverseSeed = [
            ['specs', 'specifiedBy'],
            ['documents', 'documentedBy'],
            ['specifiedBy', 'specs'],
            ['uses', 'used'],
            ['documentedBy', 'documents'],
            ['used', 'uses'],
        ];

        // 一次撈出所有 scopes 並依 is_scope_lead 分組
        $scopes = Scope::all();

        $leadScopes = $scopes->whereNull('parent_class');     // 沒有上層的
        // $nonLeadScopes = $scopes->whereNotNull('parent_class');

        // 建立固定關聯資料
        foreach ($seeds as [$from, $to, $name, $note]) {

            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            Relation::create([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'name' => $name,
                'note' => $note,
            ]);
        }

        foreach ($reverseSeed as [$subject, $reverse]) {
            $reverseId = Relation::where('name', $reverse)->value('id');
            Relation::where('name', $subject)->update(['reverse_id' => $reverseId]);
        }

        // 「assisted-by」/「assists」跟「used」/「uses」同一組 class_number，
        // 差別在 AI 只提供建議(assisted-by/assists)還是直接產出/執行成品(uses/used)。
        //
        // 2026-09-12 修正：這兩組原本用 parent_class 把 assists/assisted-by
        // 掛成 uses/used 的子關係，是誤用——assists 的定義明講「非直接產出/
        // 執行成品」，明確排除了 uses 的情況，依 RDFS rdfs7（子屬性的每一筆
        // 事實都蘊含父屬性成立），assists ⊑ uses 會導出自相矛盾的蘊含。兩者
        // 該是平輩（互斥），不是父子——比照 SPDX 3.0.1 RelationshipType 把
        // usesTool／dependsOn 並列為平輩的先例（class_number 相同、call_number
        // 不同就足夠表達「同一組裡的另一個關係」，不需要再疊加 parent_class）。
        $childSeeds = [
            ['20', '10', 'assisted-by'],
            ['10', '20', 'assists'],
        ];

        foreach ($childSeeds as [$from, $to, $name]) {
            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            Relation::create([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'call_number' => '10',
                'name' => $name,
                'note' => 'AI 輔助但非直接產出/執行成品；與 uses/used 平輩，不是子關係（比照 SPDX usesTool/dependsOn 的平輩先例）。',
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
        ]);

        $ancestorOf = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '10',
            'name' => 'ancestorOf',
            'note' => 'SPDX relationship type ANCESTOR_OF — reverse of descendantOf.',
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
        ]);

        $succeeds = Relation::create([
            'subject_id' => $implementation->id,
            'object_id' => $implementation->id,
            'class_number' => '22',
            'call_number' => '40',
            'name' => 'succeeds',
            'note' => 'Reverse of precedes.',
        ]);

        $precedes->update(['reverse_id' => $succeeds->id]);
        $succeeds->update(['reverse_id' => $precedes->id]);

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
