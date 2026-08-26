<?php

namespace Database\Factories;

use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Relation>
 */
class RelationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Scope::factory(),
            'object_id' => Scope::factory(),
            'class_number' => fake()->numerify('##'),
            'call_number' => '00',
            'name' => fake()->unique()->word(),
            'note' => fake()->sentence(),
        ];
    }
}
