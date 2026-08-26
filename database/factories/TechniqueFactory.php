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
            'version' => fake()->optional()->numerify('#.#.#'),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
