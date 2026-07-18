<?php

namespace App\Console\Commands;

use App\Models\LegacyBackupRestoreAttestation;
use App\Support\LegacyFlowAuthority;
use App\Support\LegacyRetirementWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class AttestLegacyBackupRestore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'legacy:attest-backup-restore
        {evidence : Path to the JSON restoration evidence report}
        {--attested-by= : Operator identity responsible for the restoration exercise}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Append an immutable attestation for a verified legacy backup restoration exercise';

    /**
     * Execute the console command.
     */
    public function handle(
        LegacyFlowAuthority $legacyFlowAuthority,
        LegacyRetirementWindow $retirementWindow,
    ): int {
        if (! $legacyFlowAuthority->isAuthoritative()) {
            $this->components->error('Canonical authority must be established before backup restoration can be attested.');

            return self::FAILURE;
        }

        $authority = $legacyFlowAuthority->marker();
        $observationCompletedAt = $retirementWindow->completion();
        if ($authority === null || $observationCompletedAt === null || now()->lt($observationCompletedAt)) {
            $this->components->error('The minimum canonical observation window has not completed.');

            return self::FAILURE;
        }

        $attestedBy = $this->option('attested-by');
        if (! is_string($attestedBy) || blank($attestedBy)) {
            $this->components->error('The --attested-by option is required.');

            return self::FAILURE;
        }

        $path = $this->evidencePath((string) $this->argument('evidence'));
        if (! File::isFile($path) || ! File::isReadable($path)) {
            $this->components->error("Restoration evidence file [{$path}] is not readable.");

            return self::FAILURE;
        }

        $contents = File::get($path);
        try {
            $evidence = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->components->error('Restoration evidence must be valid JSON.');

            return self::FAILURE;
        }

        if (! $this->validEvidence($evidence)) {
            $this->components->error('Restoration evidence is incomplete or contains failed integrity checks.');

            return self::FAILURE;
        }

        $evidenceHash = hash('sha256', $contents);
        $attestation = LegacyBackupRestoreAttestation::query()->firstOrCreate(
            ['evidence_sha256' => $evidenceHash],
            [
                'authority_marker' => $authority->getKey(),
                'environment' => $evidence['environment'],
                'restore_target' => $evidence['restore_target'],
                'backup_sha256' => strtolower($evidence['backup_sha256']),
                'evidence_path' => $path,
                'integrity_checks' => $evidence['integrity_checks'],
                'attested_by' => $attestedBy,
            ],
        );

        $this->components->info("Backup restoration attestation #{$attestation->id} recorded immutably.");

        return self::SUCCESS;
    }

    private function evidencePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function validEvidence(mixed $evidence): bool
    {
        if (! is_array($evidence)
            || ! is_string($evidence['environment'] ?? null)
            || blank($evidence['environment'])
            || ! is_string($evidence['restore_target'] ?? null)
            || blank($evidence['restore_target'])
            || ! is_string($evidence['backup_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/i', $evidence['backup_sha256']) !== 1
            || ! is_array($evidence['integrity_checks'] ?? null)) {
            return false;
        }

        $requiredChecks = ['schema', 'row_counts', 'application_smoke'];

        return collect($requiredChecks)->every(
            fn (string $check): bool => ($evidence['integrity_checks'][$check] ?? false) === true,
        );
    }
}
