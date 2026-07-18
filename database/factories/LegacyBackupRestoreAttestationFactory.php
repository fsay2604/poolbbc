<?php

namespace Database\Factories;

use App\Models\CanonicalFlowAuthority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LegacyBackupRestoreAttestation>
 */
class LegacyBackupRestoreAttestationFactory extends Factory
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
            'environment' => 'isolated-restore',
            'restore_target' => 'restore-test-'.fake()->uuid(),
            'backup_sha256' => hash('sha256', fake()->uuid()),
            'evidence_path' => 'evidence/'.fake()->uuid().'.json',
            'evidence_sha256' => hash('sha256', fake()->uuid()),
            'integrity_checks' => [
                'schema' => true,
                'row_counts' => true,
                'application_smoke' => true,
            ],
            'attested_by' => fake()->name(),
            'verified_at' => now(),
            'created_at' => now(),
        ];
    }
}
