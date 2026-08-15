<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(5),
            'currency' => 'GHS',
            'timezone' => 'Africa/Accra',
            'license_term_value' => 1,
            'license_term_unit' => 'years',
            'license_started_at' => now(),
            'license_expires_at' => now()->addYear(),
        ];
    }
}
