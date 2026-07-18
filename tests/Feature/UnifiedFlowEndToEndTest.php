<?php

namespace Tests\Feature;

use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Drafts\StartDraft;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Pools\JoinPool;
use App\Actions\Predictions\SubmitPoolEventPrediction;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Models\Houseguest;
use App\Models\PointEntry;
use App\Models\PoolEvent;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UnifiedFlowEndToEndTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unified_hybrid_flow_from_pool_creation_to_result_correction_and_leaderboard(): void
    {
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $owner = User::factory()->create();
        $opponent = User::factory()->create();
        $administrator = User::factory()->admin()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id,
            'name' => 'Pool unifié',
            'description' => null,
            'timezone' => 'America/Toronto',
            'competition_mode' => 'hybrid',
            'max_members' => 2,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);
        $opponentMember = app(JoinPool::class)->handle($opponent, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        app(MakeDraftPick::class)->handle($draft, $ownerMember, $houseguests[0]);
        app(MakeDraftPick::class)->handle($draft->fresh(), $opponentMember, $houseguests[1]);

        $round = SeasonRound::factory()->for($season)->create(['position' => 1]);
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'status' => EventStatus::Draft,
            'opens_at' => now(),
            'locks_at' => now()->addHour(),
            'position' => 1,
        ]);
        $event = app(TransitionSeasonEvent::class)->synchronize($event);
        $firstOption = $event->options->firstWhere('houseguest_id', $houseguests[0]->id);
        $secondOption = $event->options->firstWhere('houseguest_id', $houseguests[1]->id);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Hybrid,
            'scoring_config' => [
                'owner' => ['points_per_match' => 5],
                'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $ownerMember, [$firstOption->id]);
        app(SubmitPoolEventPrediction::class)->handle($poolEvent, $opponentMember, [$firstOption->id]);

        app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);
        app(PublishSeasonEventResult::class)->handle($event->fresh(), $administrator, [$firstOption->id]);

        $initial = app(BuildPoolLeaderboard::class)->handle($pool)->keyBy(fn (array $row): int => $row['member']->user_id);
        $this->assertSame(7, $initial[$owner->id]['total_points']);
        $this->assertSame(2, $initial[$opponent->id]['total_points']);

        app(PublishSeasonEventResult::class)->handle(
            $event->fresh(),
            $administrator,
            [$secondOption->id],
            'Résultat officiel corrigé après vérification.',
        );

        $corrected = app(BuildPoolLeaderboard::class)->handle($pool)->keyBy(fn (array $row): int => $row['member']->user_id);
        $this->assertSame(0, $corrected[$owner->id]['total_points']);
        $this->assertSame(5, $corrected[$opponent->id]['total_points']);
        $this->assertSame(3, PointEntry::query()->whereNotNull('reverses_point_entry_id')->count());
        $this->assertDatabaseCount('season_event_results', 2);
    }
}
