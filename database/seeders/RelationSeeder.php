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
            ['00', '10', 'specs'],
            ['00', '20', 'documents'],
            ['10', '00', 'specifiedBy'],
            ['10', '20', 'uses'],
            ['20', '00', 'documentedBy'],
            ['20', '10', 'used'],
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
        foreach ($seeds as [$from, $to, $name]) {

            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            Relation::create([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'name' => $name,
                'note' => 'test',
            ]);
        }

        foreach ($reverseSeed as [$subject, $reverse]) {
            $reverseId = Relation::where('name', $reverse)->value('id');
            Relation::where('name', $subject)->update(['reverse_id' => $reverseId]);
        }

        // 「assisted-by」/「assists」跟「used」/「uses」同一組 class_number,
        // 差別在 AI 只提供建議(assisted-by/assists)還是直接產出/執行成品(uses/used)
        $childSeeds = [
            ['20', '10', 'assisted-by', 'used'],
            ['10', '20', 'assists', 'uses'],
        ];

        foreach ($childSeeds as [$from, $to, $name, $parentRelationName]) {
            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);
            $parentId = Relation::where('name', $parentRelationName)->value('id');

            Relation::create([
                'subject_id' => $subject->id,
                'object_id' => $object->id,
                'parent_class' => $parentId,
                'class_number' => $subject->class_number[0].$object->class_number[0],
                'call_number' => '10',
                'name' => $name,
                'note' => 'AI 輔助但非直接產出/執行成品',
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

        $isRequiredBy = Relation::create([
            'subject_id' => $technique->id,
            'object_id' => $technique->id,
            'class_number' => '11',
            'call_number' => '10',
            'parent_class' => $requires->id,
            'name' => 'isRequiredBy',
            'note' => 'dcterms:isRequiredBy — reverse of requires.',
        ]);

        $requires->update(['reverse_id' => $isRequiredBy->id]);
        $isRequiredBy->update(['reverse_id' => $requires->id]);

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
