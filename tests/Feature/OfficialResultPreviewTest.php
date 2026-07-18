<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Houseguests\RebuildSeasonHouseguestActivity;
use App\Actions\Leaderboards\RebuildPoolScoreProjection;
use App\Actions\Scoring\PreviewSeasonEventScore;
use App\Actions\Scoring\ScoreSeasonEventResult;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Enums\ResultPublicationMode;
use App\Jobs\FinalizeSeasonEventResultJob;
use App\Jobs\ScoreSeasonEventResultJob;
use App\Models\AuditLog;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Houseguest;
use App\Models\PointEntry;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class OfficialResultPreviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_matches_the_published_ledger_for_a_member_removed_after_the_draft(): void
    {
        Bus::fake();
        $administrator = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $removedUser = User::factory()->create();
        $season = Season::factory()->create();
        $houseguest = Houseguest::factory()->for($season)->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'created_by' => $administrator->id,
            'status' => EventStatus::Draft,
            'result_publication_mode' => ResultPublicationMode::Immediate,
        ]);
        $option = SeasonEventOption::factory()->for($event, 'event')->create([
            'houseguest_id' => $houseguest->id,
            'position' => 1,
        ]);
        $event->update([
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDays(2),
            'locks_at' => now()->subDay(),
        ]);
        $pool = Pool::factory()->rosterOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);
        $removedMember = PoolMember::factory()->for($pool)->for($removedUser)->create(['draft_position' => 2]);
        $draft = Draft::factory()->for($pool)->create();
        DraftPick::factory()->for($draft)->create([
            'pool_id' => $pool->id,
            'pool_member_id' => $removedMember->id,
            'houseguest_id' => $houseguest->id,
        ]);
        $removedMember->update([
            'status' => PoolMemberStatus::Removed,
            'removed_at' => now(),
        ]);
        $poolEvent = PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $event->id,
            'mode' => EventMode::Roster,
            'scoring_config' => [
                'owner' => ['points_per_match' => 7],
                'prediction' => ['points_per_correct' => 0, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                'allow_negative' => false,
            ],
        ]);

        $previewRow = app(PreviewSeasonEventScore::class)
            ->handle($event, $administrator, [$option->id])
            ->firstWhere('member', $removedUser->name);

        $this->assertNotNull($previewRow);
        $this->assertSame(7, $previewRow['total_points']);

        $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);
        (new ScoreSeasonEventResultJob($result->id, $poolEvent->id))->handle(app(ScoreSeasonEventResult::class));
        (new FinalizeSeasonEventResultJob($result->id))->handle(
            app(\App\Actions\Events\TransitionSeasonEvent::class),
            app(RecordAuditLog::class),
            app(RebuildSeasonHouseguestActivity::class),
            app(RebuildPoolScoreProjection::class),
        );

        $ledgerPoints = (int) PointEntry::query()
            ->published()
            ->where('pool_member_id', $removedMember->id)
            ->sum('points');

        $this->assertSame($previewRow['total_points'], $ledgerPoints);
    }

    public function test_official_selection_changes_invalidate_the_preview_and_block_recording_until_refreshed(): void
    {
        [$administrator, $event, $options] = $this->manualOfficialEvent();

        $component = Livewire::actingAs($administrator)
            ->test('admin.official-rounds')
            ->call('startResult', $event->id)
            ->set('resultOptionIds', [$options[0]->id])
            ->call('publishResult')
            ->assertHasErrors(['resultOptionIds'])
            ->call('previewResult');

        $this->assertNotNull($component->get('resultPreviewFingerprint'));

        $component
            ->set('resultOptionIds', [$options[1]->id])
            ->assertSet('resultPreviewFingerprint', null)
            ->call('publishResult')
            ->assertHasErrors(['resultOptionIds'])
            ->call('previewResult')
            ->call('publishResult')
            ->assertHasNoErrors();

        $this->assertSame([$options[1]->id], $event->fresh()->draftResult?->options()->pluck('season_event_options.id')->all());
    }

    public function test_official_draft_event_can_switch_to_manual_result_publication_with_an_audit_record(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'created_by' => $administrator->id,
            'result_publication_mode' => ResultPublicationMode::Immediate,
        ]);

        Livewire::actingAs($administrator)
            ->test('admin.official-rounds')
            ->call('startEdit', $event->id)
            ->set('eventForm.result_publication_mode', 'unsupported')
            ->call('saveEvent')
            ->assertHasErrors(['eventForm.result_publication_mode'])
            ->set('eventForm.result_publication_mode', ResultPublicationMode::Manual->value)
            ->call('saveEvent')
            ->assertHasNoErrors();

        $this->assertSame(ResultPublicationMode::Manual, $event->fresh()->result_publication_mode);
        $audit = AuditLog::query()->where('action', 'season_event.updated')->latest('id')->firstOrFail();
        $this->assertSame('immediate', data_get($audit->metadata, 'before.result_publication_mode'));
        $this->assertSame('manual', data_get($audit->metadata, 'after.result_publication_mode'));
    }

    /** @return array{User, SeasonEvent, list<SeasonEventOption>} */
    private function manualOfficialEvent(): array
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        $round = SeasonRound::factory()->for($season)->create();
        $event = SeasonEvent::factory()->for($round, 'round')->create([
            'created_by' => $administrator->id,
            'status' => EventStatus::Draft,
            'result_publication_mode' => ResultPublicationMode::Manual,
        ]);
        $options = [
            SeasonEventOption::factory()->for($event, 'event')->create(['position' => 1]),
            SeasonEventOption::factory()->for($event, 'event')->create(['position' => 2]),
        ];
        $event->update([
            'status' => EventStatus::Locked,
            'opens_at' => now()->subDays(2),
            'locks_at' => now()->subDay(),
        ]);

        return [$administrator, $event, $options];
    }
}
