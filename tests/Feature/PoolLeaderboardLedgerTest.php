<?php

namespace Tests\Feature;

use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Enums\EventMode;
use App\Models\Event;
use App\Models\EventResult;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PoolLeaderboardLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ledger_ranking_keeps_zero_members_shares_ties_and_matches_each_detail_sum(): void
    {
        [$pool, $members, $poolEvents] = $this->ledgerContext();
        $this->points($members[0], $poolEvents[0], 10, 'a-round-1');
        $this->points($members[1], $poolEvents[0], 10, 'b-round-1');

        $rows = app(BuildPoolLeaderboard::class)->handle($pool);

        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows[0]['rank']);
        $this->assertSame(1, $rows[1]['rank']);
        $this->assertSame(3, $rows[2]['rank']);
        $this->assertSame(0, $rows[2]['total_points']);

        foreach ($rows as $row) {
            $this->assertSame($row['total_points'], (int) $row['member']->pointEntries->sum('points'));
        }
    }

    public function test_round_filter_and_rank_variation_are_derived_from_the_ledger(): void
    {
        [$pool, $members, $poolEvents, $rounds] = $this->ledgerContext();
        $this->points($members[0], $poolEvents[0], 5, 'a-round-1');
        $this->points($members[1], $poolEvents[0], 10, 'b-round-1');
        $this->points($members[0], $poolEvents[1], 10, 'a-round-2');

        $rows = app(BuildPoolLeaderboard::class)->handle($pool);

        $first = $rows->firstWhere(fn (array $row): bool => $row['member']->is($members[0]));
        $second = $rows->firstWhere(fn (array $row): bool => $row['member']->is($members[1]));
        $this->assertSame(1, $first['rank_change']);
        $this->assertSame(-1, $second['rank_change']);

        $filtered = app(BuildPoolLeaderboard::class)->handle($pool, ['round' => 'official:'.$rounds[1]->id]);
        $filteredFirst = $filtered->firstWhere(fn (array $row): bool => $row['member']->is($members[0]));
        $filteredSecond = $filtered->firstWhere(fn (array $row): bool => $row['member']->is($members[1]));

        $this->assertSame(10, $filteredFirst['total_points']);
        $this->assertSame(0, $filteredSecond['total_points']);
        $this->assertNull($filteredFirst['rank_change']);
        $this->assertNull($filteredSecond['rank_change']);
        $this->assertCount(1, $filteredFirst['member']->pointEntries);
    }

    public function test_rank_variation_uses_the_latest_local_round_in_a_local_only_pool(): void
    {
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $members = [
            PoolMember::factory()->for($pool)->create(['draft_position' => 1]),
            PoolMember::factory()->for($pool)->create(['draft_position' => 2]),
            PoolMember::factory()->for($pool)->create(['draft_position' => 3]),
        ];
        $firstRound = Round::factory()->for($pool)->create(['position' => 1]);
        $secondRound = Round::factory()->for($pool)->create(['position' => 2]);
        [$firstEvent, $firstResult] = $this->localEvent($firstRound);
        [$secondEvent, $secondResult] = $this->localEvent($secondRound);

        $this->localPoints($members[0], $firstEvent, $firstResult, 5, 'local-a-round-1');
        $this->localPoints($members[1], $firstEvent, $firstResult, 10, 'local-b-round-1');
        $this->localPoints($members[0], $secondEvent, $secondResult, 10, 'local-a-round-2');

        $rows = app(BuildPoolLeaderboard::class)->handle($pool)->keyBy(fn (array $row): int => $row['member']->id);

        $this->assertSame(15, $rows[$members[0]->id]['total_points']);
        $this->assertSame(1, $rows[$members[0]->id]['rank_change']);
        $this->assertSame(-1, $rows[$members[1]->id]['rank_change']);
        $this->assertSame(0, $rows[$members[2]->id]['rank_change']);
    }

    public function test_rank_variation_combines_local_and_official_events_at_the_latest_round_position(): void
    {
        [$pool, $members, $poolEvents] = $this->ledgerContext();
        $localRound = Round::factory()->for($pool)->create(['position' => 2]);
        [$localEvent, $localResult] = $this->localEvent($localRound);

        $this->points($members[0], $poolEvents[0], 5, 'mixed-a-round-1');
        $this->points($members[1], $poolEvents[0], 10, 'mixed-b-round-1');
        $this->points($members[0], $poolEvents[1], 10, 'mixed-a-official-round-2');
        $this->localPoints($members[0], $localEvent, $localResult, 6, 'mixed-a-local-round-2');

        $rows = app(BuildPoolLeaderboard::class)->handle($pool)->keyBy(fn (array $row): int => $row['member']->id);

        $this->assertSame(21, $rows[$members[0]->id]['total_points']);
        $this->assertSame(1, $rows[$members[0]->id]['rank_change']);
        $this->assertSame(-1, $rows[$members[1]->id]['rank_change']);
        $this->assertSame(0, $rows[$members[2]->id]['rank_change']);
    }

    public function test_leaderboard_query_count_stays_bounded_as_members_are_added(): void
    {
        [$pool] = $this->ledgerContext();
        foreach (range(4, 30) as $draftPosition) {
            PoolMember::factory()->for($pool)->create(['draft_position' => $draftPosition]);
        }
        Cache::forget(BuildPoolLeaderboard::cacheKey($pool->id));
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $rows = app(BuildPoolLeaderboard::class)->handle($pool);

        $this->assertCount(30, $rows);
        $this->assertLessThanOrEqual(15, $queryCount, "Leaderboard query budget exceeded: {$queryCount} queries.");
    }

    /** @return array{Pool, list<PoolMember>, list<PoolEvent>, list<SeasonRound>} */
    private function ledgerContext(): array
    {
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create(['season_id' => $season->id]);
        $members = [
            PoolMember::factory()->for($pool)->create(['draft_position' => 1]),
            PoolMember::factory()->for($pool)->create(['draft_position' => 2]),
            PoolMember::factory()->for($pool)->create(['draft_position' => 3]),
        ];
        $rounds = [
            SeasonRound::factory()->for($season)->create(['position' => 1]),
            SeasonRound::factory()->for($season)->create(['position' => 2]),
        ];
        $poolEvents = [];

        foreach ($rounds as $position => $round) {
            $event = SeasonEvent::factory()->for($round, 'round')->create(['position' => $position + 1]);
            $poolEvents[] = PoolEvent::factory()->create([
                'pool_id' => $pool->id,
                'season_event_id' => $event->id,
                'mode' => EventMode::Prediction,
            ]);
        }

        return [$pool, $members, $poolEvents, $rounds];
    }

    private function points(PoolMember $member, PoolEvent $poolEvent, int $points, string $key): void
    {
        PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'pool_event_id' => $poolEvent->id,
            'type' => 'prediction',
            'points' => $points,
            'reason' => 'Test ledger',
            'idempotency_key' => $key,
        ]);
    }

    /** @return array{Event, EventResult} */
    private function localEvent(Round $round): array
    {
        $event = Event::factory()->for($round)->create();

        return [$event, EventResult::factory()->for($event)->create()];
    }

    private function localPoints(
        PoolMember $member,
        Event $event,
        EventResult $result,
        int $points,
        string $key,
    ): void {
        PointEntry::query()->create([
            'pool_member_id' => $member->id,
            'event_id' => $event->id,
            'event_result_id' => $result->id,
            'type' => 'prediction',
            'points' => $points,
            'reason' => 'Test local ledger',
            'idempotency_key' => $key,
        ]);
    }
}
