<?php

namespace Database\Factories;

use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\scope>
 */
class ScopeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */

    protected $model = Scope::class;

    public function definition(): array
    {
        return [
            'class_number' => fake()->numberBetween(10, 20),
            'call_number' => $call = fake()->numerify('##'),
            'is_scope_lead' => $call === '00',
            'name' => fake()->unique()->word(),
            'comment' => fake()->text(50)
        ];
    }
}
