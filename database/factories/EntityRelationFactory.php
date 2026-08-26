<?php

namespace Database\Factories;

use App\Models\Documentation;
use App\Models\EntityRelation;
use App\Models\Relation;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntityRelationFactory extends Factory
{
    protected $model = EntityRelation::class;

    public function definition(): array
    {
        return [
            'entity_type' => 'technique',
            'subject_id' => Technique::factory(),
            'object_id' => Technique::factory(),
            'relation_id' => Relation::factory(),
        ];
    }

    public function documentation(): static
    {
        return $this->state(fn () => [
            'entity_type' => 'documentation',
            'subject_id' => Documentation::factory(),
            'object_id' => Documentation::factory(),
        ]);
    }
}
