<?php

namespace App\Console\Commands;

use App\Actions\Migrations\CompareLegacyAndLedger as CompareAction;
use App\Models\LegacyShadowObservation;
use App\Models\Season;
use App\Support\LegacyFlowAuthority;
use Illuminate\Console\Command;

class CompareLegacyAndLedger extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'legacy:shadow-compare
        {--season= : Compare one season ID}
        {--threshold=0 : Maximum unapproved week/season comparison rows}
        {--allow-draft-exclusions : Approve differences caused only by historical draft scores}
        {--record : Append an immutable observation linked to canonical authority}
        {--json : Emit the complete JSON report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read-only shadow comparison of legacy scores and the imported PointEntry ledger';

    /**
     * Execute the console command.
     */
    public function handle(CompareAction $compare, LegacyFlowAuthority $legacyFlowAuthority): int
    {
        $query = Season::query()->orderBy('id');
        if (filled($this->option('season'))) {
            $query->whereKey((int) $this->option('season'));
        } else {
            $query->where(fn ($legacyQuery) => $legacyQuery
                ->whereHas('weeks')
                ->orWhereHas('seasonPredictions'));
        }

        $reports = $query->get()->map(fn (Season $season): array => $compare->handle(
            $season,
            (bool) $this->option('allow-draft-exclusions'),
        ));
        $differences = (int) $reports->sum('unapproved_differences');

        if ($this->option('json')) {
            $this->line($reports->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Saison', 'Pool', 'Membres', 'Écarts non approuvés'],
                $reports->map(fn (array $report): array => [
                    $report['season_id'],
                    $report['pool_id'] ?? 'absent',
                    count($report['members']),
                    $report['unapproved_differences'],
                ]),
            );
        }

        if ($this->option('record')) {
            if (! $legacyFlowAuthority->isAuthoritative()) {
                if (! $this->option('json')) {
                    $this->components->error('Shadow observations can only be recorded after canonical authority is established.');
                }

                return self::FAILURE;
            }

            $authority = $legacyFlowAuthority->marker();
            if ($authority === null) {
                if (! $this->option('json')) {
                    $this->components->error('The canonical authority marker is missing.');
                }

                return self::FAILURE;
            }

            LegacyShadowObservation::query()->create([
                'authority_marker' => $authority->getKey(),
                'environment' => app()->environment(),
                'unapproved_differences' => $differences,
                'report_hash' => hash('sha256', $reports->toJson(JSON_UNESCAPED_UNICODE)),
            ]);
            if (! $this->option('json')) {
                $this->components->info('Immutable shadow observation recorded.');
            }
        }

        $threshold = max(0, (int) $this->option('threshold'));
        if ($differences > $threshold) {
            if (! $this->option('json')) {
                $this->components->error("Cutover blocked: {$differences} unapproved differences exceed threshold {$threshold}.");
            }

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->components->info('Shadow comparison passed. The ledger was not modified.');
        }

        return self::SUCCESS;
    }
}
