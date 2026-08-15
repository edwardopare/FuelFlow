<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    protected $model = Station::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ST-###')),
            'station_number' => strtoupper(fake()->unique()->bothify('NO-####')),
            'name' => fake()->city().' Station',
            'timezone' => 'Africa/Accra',
            'registration_number' => fake()->optional()->numerify('GHA-########'),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'is_active' => true,
        ];
    }
}
