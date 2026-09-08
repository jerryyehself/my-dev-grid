<?php

namespace Database\Factories;

use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OauthIdentity>
 */
class OauthIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(['google', 'line']),
            'provider_user_id' => (string) fake()->unique()->numerify('##########'),
            'provider_email' => fake()->unique()->safeEmail(),
        ];
    }
}
