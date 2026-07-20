<?php

namespace Tests\Feature;

use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\EventPrediction;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolEventPrediction;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PoolDashboardPredictionSummaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summary_counts_the_same_official_and_local_predictions_as_the_unified_journey(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'owner_id' => $owner->id,
            'season_id' => $season->id,
        ]);
        $member = PoolMember::factory()->for($pool)->for($owner)->create([
            'role' => PoolMemberRole::Owner,
        ]);
        $officialRound = SeasonRound::factory()->for($season)->create();
        $officialEvent = SeasonEvent::factory()->for($officialRound, 'round')->create([
            'status' => EventStatus::Open,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHours(2),
        ]);
        $officialPoolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $officialEvent->id,
            'mode' => EventMode::Prediction,
        ]);
        PoolEventPrediction::factory()->create([
            'pool_event_id' => $officialPoolEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $cancelledOfficialEvent = SeasonEvent::factory()->for($officialRound, 'round')->create([
            'status' => EventStatus::Cancelled,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHour(),
        ]);
        $cancelledOfficialPoolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $cancelledOfficialEvent->id,
            'mode' => EventMode::Prediction,
        ]);
        PoolEventPrediction::factory()->create([
            'pool_event_id' => $cancelledOfficialPoolEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $localRound = Round::factory()->for($pool)->create();
        $localEvent = Event::factory()->for($localRound)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => EventStatus::Open,
            'position' => 1,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addHour(),
        ]);
        EventPrediction::factory()->create([
            'event_id' => $localEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $cancelledLocalEvent = Event::factory()->for($localRound)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => EventStatus::Cancelled,
            'position' => 2,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addMinutes(30),
        ]);
        EventPrediction::factory()->create([
            'event_id' => $cancelledLocalEvent->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $localProjection = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => null,
            'local_event_id' => $localEvent->id,
            'mode' => EventMode::Prediction,
        ]);
        PoolEventPrediction::factory()->create([
            'pool_event_id' => $localProjection->id,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $foreignSeason = Season::factory()->create();
        $foreignRound = SeasonRound::factory()->for($foreignSeason)->create();
        $foreignEvent = SeasonEvent::factory()->for($foreignRound, 'round')->create([
            'status' => EventStatus::Open,
            'opens_at' => now()->subHour(),
            'locks_at' => now()->addMinutes(15),
        ]);
        $foreignPoolEventId = DB::table('pool_events')->insertGetId([
            'pool_id' => $pool->id,
            'season_event_id' => $foreignEvent->id,
            'local_event_id' => null,
            'mode' => EventMode::Prediction->value,
            'is_active' => true,
            'visibility' => 'after_lock',
            'prediction_min_selections' => 1,
            'prediction_max_selections' => 1,
            'scoring_config' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PoolEventPrediction::factory()->create([
            'pool_event_id' => $foreignPoolEventId,
            'pool_member_id' => $member->id,
            'status' => PredictionStatus::Submitted,
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($owner)
            ->test('pools.predictions', ['pool' => $pool])
            ->assertSet('totalCount', 2)
            ->assertSet('submittedCount', 2);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool])
            ->assertSet('summary.predictions_total', 2)
            ->assertSet('summary.predictions_submitted', 2);
    }

    public function test_next_deadline_only_uses_an_event_that_is_currently_open_for_predictions(): void
    {
        $this->travelTo(now()->startOfSecond());

        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'owner_id' => $owner->id,
            'season_id' => $season->id,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create([
            'role' => PoolMemberRole::Owner,
        ]);
        $officialRound = SeasonRound::factory()->for($season)->create();
        $localRound = Round::factory()->for($pool)->create();

        foreach ([
            [EventStatus::Locked, 5, true],
            [EventStatus::ResultEntered, 10, true],
            [EventStatus::Published, 15, true],
            [EventStatus::Cancelled, 20, true],
            [EventStatus::Draft, 30, false],
        ] as [$status, $locksInMinutes, $hasOpened]) {
            $this->createOfficialEvent($pool, $officialRound, $status, $locksInMinutes, $hasOpened);
            $this->createLocalEvent($pool, $localRound, $owner, $status, $locksInMinutes + 1, $hasOpened);
        }

        $respondableLocalEvent = $this->createLocalEvent(
            $pool,
            $localRound,
            $owner,
            EventStatus::Open,
            45,
            true,
            'Locale ouverte',
        );
        $respondableOfficialEvent = $this->createOfficialEvent(
            $pool,
            $officialRound,
            EventStatus::Open,
            60,
            true,
            'Officielle ouverte',
        );

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool])
            ->assertSet('summary.next_event', $respondableLocalEvent->name)
            ->assertSet(
                'summary.next_deadline',
                $respondableLocalEvent->locks_at->timezone($pool->timezone)->format('d/m H:i'),
            );

        $respondableLocalEvent->update(['status' => EventStatus::Locked]);
        $respondableOfficialEvent->update(['status' => EventStatus::Locked]);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool->fresh()])
            ->assertSet('summary.next_deadline', null)
            ->assertSet('summary.next_event', null);
    }

    private function createOfficialEvent(
        Pool $pool,
        SeasonRound $round,
        EventStatus $status,
        int $locksInMinutes,
        bool $hasOpened,
        ?string $name = null,
    ): SeasonEvent {
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'name' => $name ?? "Officielle {$status->value}",
            'status' => $status,
            'opens_at' => $hasOpened ? now()->subHour() : now()->addHour(),
            'locks_at' => now()->addMinutes($locksInMinutes),
        ]);
        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Prediction,
        ]);

        return $event;
    }

    private function createLocalEvent(
        Pool $pool,
        Round $round,
        User $owner,
        EventStatus $status,
        int $locksInMinutes,
        bool $hasOpened,
        ?string $name = null,
    ): Event {
        $nextPosition = ((int) Event::query()->whereBelongsTo($round)->max('position')) + 1;

        return Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'name' => $name ?? "Locale {$status->value}",
            'status' => $status,
            'position' => $nextPosition,
            'opens_at' => $hasOpened ? now()->subHour() : now()->addHour(),
            'locks_at' => now()->addMinutes($locksInMinutes),
        ]);
    }
}
