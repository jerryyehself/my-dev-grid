<?php

namespace Database\Seeders;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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

        // 一次撈出所有 scopes 並依 is_scope_lead 分組
        $groupedScopes = Scope::all()->groupBy('is_scope_lead');

        $leadScopes = $groupedScopes[1] ?? collect();
        $nonLeadScopes = $groupedScopes[0] ?? collect();

        // 建立固定關聯資料
        foreach ($seeds as [$from, $to, $title]) {

            $subject = $leadScopes->firstWhere('class_number', $from);
            $object = $leadScopes->firstWhere('class_number', $to);

            Relation::create([
                'subject_id' =>  $subject->id,
                'object_id'  => $object->id,
                'class_number' => $subject->class_number[0] . $object->class_number[0],
                'title' => $title,
                'note' => 'test',
            ]);
        }

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

                $relationData = Relation::factory()->make([
                    'subject_id' => $subject->id,
                    'object_id'  => $object->id,
                    'class_number' => $classNumber,
                    'call_number'  => str_pad($serials[$classNumber], 2, '0', STR_PAD_LEFT),
                ])->toArray();

                $relationData['class_number'] = $subject->class_number[0] . $object->class_number[0];
                $relationData['created_at'] = now()->format('Y-m-d H:i:s');
                $relationData['updated_at'] = now()->format('Y-m-d H:i:s');

                $relations->add($relationData);
            }

            Relation::insert($relations->values()->toArray());
        }
    }
}
