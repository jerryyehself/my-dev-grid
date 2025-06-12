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
        $classNumber = fake()->numberBetween(10, 20);
        $usedCallNum = Scope::where('class_number', $classNumber)->pluck('call_number')->toArray();

        $availableCallNum = collect(range(0, 99))
            ->map(fn($n) => str_pad($n, 2, '0', STR_PAD_LEFT))
            ->diff($usedCallNum)
            ->values()
            ->random();

        return [
            'class_number' => $classNumber,
            'call_number' => $availableCallNum,
            'is_scope_lead' => $availableCallNum === '00',
            'name' => fake()->unique()->word(),
            'comment' => fake()->text(50)
        ];
    }
}
