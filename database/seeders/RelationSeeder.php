<?php

namespace Database\Seeders;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
                'subject_id' =>  $subject->id,
                'object_id'  => $object->id,
                'class_number' => $subject->class_number[0] . $object->class_number[0],
                'name' => $name,
                'note' => 'test',
            ]);
        }

        foreach ($reverseSeed as [$subject, $reverse]) {
            $reverseId = Relation::where('name', $reverse)->value('id');
            Relation::where('name', $subject)->update(['reverse_id' => $reverseId]);
        }

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
                $object  = $nonLeadScopes->where('id', '!=', $subject->id)->random();

                $classNumber = str($subject->class_number)[0] . str($object->class_number)[0];
                $serials[$classNumber] = !isset($serials[$classNumber]) ? 1 : ++$serials[$classNumber];

                $callNumber = str_pad($serials[$classNumber], 2, '0', STR_PAD_LEFT);

                $parent = null;
                if ($callNumber != '00') {
                    $parent = Relation::where('class_number', $classNumber)
                        ->where('call_number', '00')
                        ->value('id');
                }

                $relation = Relation::factory()->make([
                    'subject_id' => $subject->id,
                    'object_id'  => $object->id,
                    'parent_class' => $parent,
                    'class_number' => $classNumber,
                    'call_number' => $callNumber,
                ]);

                $relationData = Arr::except($relation->attributesToArray(), ['CURIE']);

                $relationData['created_at'] = now()->format('Y-m-d H:i:s');
                $relationData['updated_at'] = now()->format('Y-m-d H:i:s');

                $relations->add($relationData);
            }

            Relation::insert($relations->values()->toArray());
        }
    }
}
