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
                'is_scope_lead' => true,
                'name' => 'Documentation',
                'comment' => 'test'
            ],
            [
                'class_number' => '00',
                'call_number' => '10',
                'name' => 'sourcesite',
                'comment' => 'test'
            ],
            [
                'class_number' => '00',
                'call_number' => '20',
                'name' => 'document',
                'comment' => 'test'
            ],
            [
                'class_number' => '00',
                'call_number' => '30',
                'name' => 'post',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'is_scope_lead' => true,
                'name' => 'technique',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '10',
                'name' => 'language',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '20',
                'name' => 'environment',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '30',
                'name' => 'packagetool',
                'comment' => 'test'
            ],
            [
                'class_number' => '10',
                'call_number' => '40',
                'name' => 'framework',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'name' => 'implementation',
                'is_scope_lead' => true,
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '10',
                'name' => 'work',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '20',
                'name' => 'project',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '30',
                'name' => 'problem',
                'comment' => 'test'
            ],
            [
                'class_number' => '20',
                'call_number' => '40',
                'name' => 'picture',
                'comment' => 'test'
            ]
        ];


        collect($seeds)->each(fn($seed) => Scope::create($seed));

        Scope::factory()->count(10)->create();
    }
}
