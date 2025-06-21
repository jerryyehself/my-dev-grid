<?php

namespace Database\Factories;

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
            'subject_id' => 1,
            'object_id' => 2,
            'name' => fake()->word(),
            'note' => fake()->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
