<?php

namespace App\Console\Commands;

use App\Actions\Migrations\CompareLegacyAndLedger;
use App\Models\CanonicalFlowAuthority;
use App\Models\LegacyBackupRestoreAttestation;
use App\Models\LegacyShadowObservation;
use App\Models\Season;
use App\Support\LegacyFlowAuthority;
use App\Support\LegacyRetirementWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class LegacyRetirementReadiness extends Command
{
    protected $signature = 'legacy:retirement-readiness';

    protected $description = 'Verify canonical authority, observation, shadow parity, and backup restoration before legacy retirement';

    public function handle(
        CompareLegacyAndLedger $compare,
        LegacyFlowAuthority $legacyFlowAuthority,
        LegacyRetirementWindow $retirementWindow,
    ): int {
        $authoritative = $legacyFlowAuthority->isAuthoritative();
        $authority = $legacyFlowAuthority->marker();
        $minimumDays = $retirementWindow->minimumDays();
        $observationStartedAt = $retirementWindow->effectiveStart();
        $observationCompletedAt = $retirementWindow->completion();
        $observedDays = $retirementWindow->observedDays();
        $shadowDifferences = Season::query()
            ->where(fn ($legacyQuery) => $legacyQuery
                ->whereHas('weeks')
                ->orWhereHas('seasonPredictions'))
            ->get()
            ->sum(fn (Season $season): int => $compare->handle($season, true)['unapproved_differences']);
        $stableShadowCoverage = $this->hasStableShadowCoverage(
            $authority,
            $observationStartedAt,
            $observationCompletedAt,
        );
        $backupRestoreAttested = $this->hasVerifiedBackupRestoreAttestation(
            $authority,
            $observationCompletedAt,
        );
        $checks = [
            ['Canonical flow authoritative', $authoritative ? 'ready' : 'blocked'],
            ['Immutable authority marker recorded', $authority !== null ? 'ready' : 'blocked'],
            ['Observation start recorded and anchored to authority', $observationStartedAt !== null ? 'ready' : 'blocked'],
            ["Observation >= {$minimumDays} days", $observedDays >= $minimumDays ? 'ready' : "blocked ({$observedDays} days)"],
            ['Stable immutable shadow history (maximum 36-hour gap)', $stableShadowCoverage ? 'ready' : 'blocked'],
            ['Unapproved shadow differences', $shadowDifferences === 0 ? 'ready' : "blocked ({$shadowDifferences})"],
            ['Backup restoration attested after observation', $backupRestoreAttested ? 'ready' : 'blocked'],
        ];
        $this->table(['Gate', 'Status'], $checks);

        $ready = collect($checks)->every(fn (array $check): bool => $check[1] === 'ready');
        if (! $ready) {
            $this->components->warn('Legacy retirement is intentionally blocked. Keep rollback available and do not drop historical tables.');

            return self::FAILURE;
        }

        $this->components->info('Retirement gates passed with immutable shadow history and backup restoration evidence; prepare the dedicated forward-only removal migration.');

        return self::SUCCESS;
    }

    private function hasStableShadowCoverage(
        ?CanonicalFlowAuthority $authority,
        ?Carbon $observationStartedAt,
        ?Carbon $observationCompletedAt,
    ): bool {
        if ($authority === null || $observationStartedAt === null || $observationCompletedAt === null) {
            return false;
        }

        $observations = LegacyShadowObservation::query()
            ->where('authority_marker', $authority->getKey())
            ->where('environment', app()->environment())
            ->where('observed_at', '>=', $observationStartedAt)
            ->where('observed_at', '<=', now())
            ->orderBy('observed_at')
            ->get();

        if ($observations->count() < 2
            || $observations->first()->observed_at->gt($observationStartedAt->copy()->addHours(36))
            || $observations->last()->observed_at->lt($observationCompletedAt)
            || $observations->contains(
                fn (LegacyShadowObservation $observation): bool => $observation->unapproved_differences !== 0,
            )) {
            return false;
        }

        for ($index = 1; $index < $observations->count(); $index++) {
            if ($observations[$index - 1]->observed_at->diffInHours($observations[$index]->observed_at) > 36) {
                return false;
            }
        }

        return true;
    }

    private function hasVerifiedBackupRestoreAttestation(
        ?CanonicalFlowAuthority $authority,
        ?Carbon $observationCompletedAt,
    ): bool {
        if ($authority === null || $observationCompletedAt === null) {
            return false;
        }

        return LegacyBackupRestoreAttestation::query()
            ->where('authority_marker', $authority->getKey())
            ->where('verified_at', '>=', $observationCompletedAt)
            ->orderByDesc('verified_at')
            ->get()
            ->contains(function (LegacyBackupRestoreAttestation $attestation): bool {
                $checks = $attestation->integrity_checks;
                if (! is_array($checks)
                    || ($checks['schema'] ?? false) !== true
                    || ($checks['row_counts'] ?? false) !== true
                    || ($checks['application_smoke'] ?? false) !== true
                    || ! File::isFile($attestation->evidence_path)
                    || ! is_readable($attestation->evidence_path)) {
                    return false;
                }

                return hash_equals(
                    $attestation->evidence_sha256,
                    hash('sha256', File::get($attestation->evidence_path)),
                );
            });
    }
}
