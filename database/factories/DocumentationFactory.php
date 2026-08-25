<?php

namespace Database\Factories;

use App\Models\Documentation;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentationFactory extends Factory
{
    protected $model = Documentation::class;

    public function definition(): array
    {
        return [
            'type' => Scope::factory(),
            'title' => fake()->unique()->sentence(3),
            'url' => fake()->url(),
            'uri' => null,
            'note' => fake()->optional()->sentence(),
            'status' => 1,
            'creation_date' => fake()->date(),
        ];
    }
}
