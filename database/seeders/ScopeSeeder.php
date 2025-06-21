<?php

namespace Database\Seeders;

use App\Models\Scope;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ScopeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeds = [
            [
                'class_number' => '00',
                'name' => 'Documentation',
                'comment' => 'test'
            ],
            [
                'class_number' => '00',
                'call_number' => '10',
                'parent_class' => '1',
                'name' => 'sourcesite',
                'comment' => 'test'
            ],
            [
                'class_number' => '00',
                'call_number' => '20',
                'parent_class' => '1',
                'name' => 'document',
                'comment' => 'test'
            ],
            [
                'class_number' => '00',
                'call_number' => '30',
                'parent_class' => '1',
                'name' => 'post',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'name' => 'Technique',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '10',
                'parent_class' => '5',
                'name' => 'language',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '20',
                'parent_class' => '5',
                'name' => 'environment',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '30',
                'parent_class' => '5',
                'name' => 'packagetool',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '40',
                'parent_class' => '5',
                'name' => 'framework',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'name' => 'Implementation',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '10',
                'parent_class' => '10',
                'name' => 'work',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '20',
                'parent_class' => '10',
                'name' => 'project',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '30',
                'parent_class' => '10',
                'name' => 'problem',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '40',
                'parent_class' => '10',
                'name' => 'picture',
                'comment' => 'test'
            ]
        ];

        collect($seeds)->each(fn($seed) => Scope::create($seed));

        Scope::factory()->count(10)->create();
    }
}
