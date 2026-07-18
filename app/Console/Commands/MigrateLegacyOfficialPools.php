<?php

namespace App\Console\Commands;

use App\Actions\Migrations\MigrateLegacySeasonToOfficialPool;
use App\Models\Season;
use App\Support\LegacyFlowAuthority;
use Illuminate\Console\Command;
use Throwable;

class MigrateLegacyOfficialPools extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'legacy:migrate-official-pools {--season= : Import only one legacy season ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Idempotently migrate legacy predictions and scores into official PredictionOnly pools';

    /**
     * Execute the console command.
     */
    public function handle(MigrateLegacySeasonToOfficialPool $migrate, LegacyFlowAuthority $legacyFlowAuthority): int
    {
        if ($legacyFlowAuthority->cutoverEnabled()) {
            $this->components->error('Legacy import is disabled because the canonical flow is authoritative.');

            return self::FAILURE;
        }

        $query = Season::query()->orderBy('id');
        if (filled($this->option('season'))) {
            $query->whereKey((int) $this->option('season'));
        }

        $failed = false;
        $query->each(function (Season $season) use ($migrate, &$failed): void {
            try {
                $pool = $migrate->handle($season);
                $this->components->info("{$season->name}: pool #{$pool->id} imported");
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->error("{$season->name}: {$exception->getMessage()}");
                report($exception);
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
