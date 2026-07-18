<?php

namespace Database\Factories;

use App\Models\CanonicalFlowAuthority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LegacyShadowObservation>
 */
class LegacyShadowObservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authority_marker' => CanonicalFlowAuthority::MARKER,
            'environment' => 'testing',
            'unapproved_differences' => 0,
            'report_hash' => hash('sha256', fake()->uuid()),
            'observed_at' => now(),
            'created_at' => now(),
        ];
    }
}
