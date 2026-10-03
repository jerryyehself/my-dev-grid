<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Factories\Factory;

class TechniqueFactory extends Factory
{
    protected $model = Technique::class;

    public function definition(): array
    {
        return [
            'type' => Scope::factory(),
            'title' => fake()->unique()->word(),
            // 2026-09-30 起 version 有值代表「這筆是某個技術的其中一個版本」（見
            // add_technique_version_relations migration），預設留空；要版本的測試自己指定
            'version' => null,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
