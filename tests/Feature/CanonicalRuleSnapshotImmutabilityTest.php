<?php

namespace Tests\Feature;

use App\Enums\PoolStatus;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonRound;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalRuleSnapshotImmutabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_rejects_bulk_event_and_option_rule_changes_after_a_response(): void
    {
        $season = Season::factory()->create();
        $event = SeasonEvent::factory()->for(SeasonRound::factory()->for($season), 'round')->create();
        $option = SeasonEventOption::factory()->for($event, 'event')->create();
        $pool = Pool::factory()->create(['season_id' => $season->id]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
        ]);
        PoolEventPrediction::factory()->create(['pool_event_id' => $poolEvent->id]);

        $this->assertQueryRejected(fn (): int => DB::table('season_events')
            ->where('id', $event->id)
            ->update(['scoring_config' => json_encode(['owner' => ['points_per_match' => 999]], JSON_THROW_ON_ERROR)]));
        $this->assertQueryRejected(fn (): int => DB::table('season_event_options')
            ->where('id', $option->id)
            ->update(['label' => 'Mutation interdite']));

        $this->assertNotSame(999, data_get($event->fresh()->scoring_config, 'owner.points_per_match'));
        $this->assertNotSame('Mutation interdite', $option->fresh()->label);
    }

    public function test_database_rejects_bulk_pool_rule_changes_after_competition_starts(): void
    {
        $pool = Pool::factory()->create(['status' => PoolStatus::Active]);

        $this->assertQueryRejected(fn (): int => DB::table('pools')
            ->where('id', $pool->id)
            ->update(['picks_per_member' => 9]));

        $this->assertNotSame(9, $pool->fresh()->picks_per_member);
    }

    public function test_database_allows_rule_updates_while_snapshots_are_still_configurable(): void
    {
        $season = Season::factory()->create();
        $event = SeasonEvent::factory()->for(SeasonRound::factory()->for($season), 'round')->create();
        $option = SeasonEventOption::factory()->for($event, 'event')->create();
        $pool = Pool::factory()->create([
            'season_id' => $season->id,
            'status' => PoolStatus::Registration,
        ]);

        $this->assertSame(1, DB::table('season_events')->where('id', $event->id)->update(['name' => 'Nom ajusté']));
        $this->assertSame(1, DB::table('season_event_options')->where('id', $option->id)->update(['label' => 'Option ajustée']));
        $this->assertSame(1, DB::table('pools')->where('id', $pool->id)->update(['picks_per_member' => 3]));
    }

    private function assertQueryRejected(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('The database accepted a mutation to a frozen canonical rules snapshot.');
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            $this->assertTrue(
                str_contains($message, 'immutable') || str_contains($message, 'frozen'),
                $message,
            );
        }
    }
}
