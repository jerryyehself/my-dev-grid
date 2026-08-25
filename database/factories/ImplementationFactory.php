<?php

namespace Database\Factories;

use App\Models\Implementation;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImplementationFactory extends Factory
{
    protected $model = Implementation::class;

    public function definition(): array
    {
        return [
            'type' => Scope::factory(),
            'title' => fake()->unique()->sentence(3),
            'sub_title' => fake()->optional()->sentence(2),
            'description' => fake()->optional()->sentence(),
            'url' => fake()->url(),
            'git_repo_id' => fake()->optional()->numerify('#########'),
            'is_visible' => true,
            'maintain_status' => fake()->optional()->boolean(),
        ];
    }
}
