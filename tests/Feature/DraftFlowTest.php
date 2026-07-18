<?php

namespace Tests\Feature;

use App\Actions\Drafts\ChangeDraftStatus;
use App\Actions\Drafts\CorrectDraftPick;
use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Drafts\SetDraftOrder;
use App\Actions\Drafts\StartDraft;
use App\Actions\Pools\JoinPool;
use App\Enums\DraftStatus;
use App\Enums\PoolMemberStatus;
use App\Models\AuditLog;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DraftFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_snake_draft_advances_turns_and_becomes_immutable_when_complete(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(4)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Repêchage serpent', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 2,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $secondMember = app(JoinPool::class)->handle($secondUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);

        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        $this->assertSame($ownerMember->id, $draft->current_pool_member_id);

        app(MakeDraftPick::class)->handle($draft, $ownerMember, $houseguests[0]);
        $this->assertSame($secondMember->id, $draft->fresh()->current_pool_member_id);

        app(MakeDraftPick::class)->handle($draft->fresh(), $secondMember, $houseguests[1]);
        $this->assertSame($secondMember->id, $draft->fresh()->current_pool_member_id);

        app(MakeDraftPick::class)->handle($draft->fresh(), $secondMember, $houseguests[2]);
        $this->assertSame($ownerMember->id, $draft->fresh()->current_pool_member_id);

        app(MakeDraftPick::class)->handle($draft->fresh(), $ownerMember, $houseguests[3]);
        $this->assertSame(DraftStatus::Completed, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->current_pool_member_id);
        $this->assertSame(4, $draft->picks()->count());
    }

    public function test_an_exclusive_draft_rejects_a_houseguest_already_selected(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Exclusif', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        $secondMember = app(JoinPool::class)->handle($secondUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        app(MakeDraftPick::class)->handle($draft, $ownerMember, $houseguests[0]);

        $this->expectException(ValidationException::class);

        app(MakeDraftPick::class)->handle($draft->fresh(), $secondMember, $houseguests[0]);
    }

    public function test_manager_confirms_a_manual_order_before_start_and_can_pause_and_resume(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $season = Season::factory()->create();
        Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Ordre confirmé', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        $ownerMember = $pool->memberFor($owner);
        $secondMember = app(JoinPool::class)->handle($secondUser, $pool->invite_code);

        $draft = app(SetDraftOrder::class)->handle($pool->draft, $owner, [$secondMember->id, $ownerMember->id]);
        $this->assertSame(1, $secondMember->fresh()->draft_position);
        $this->assertSame(2, $ownerMember->fresh()->draft_position);

        $draft = app(StartDraft::class)->handle($draft, $owner);
        $this->assertSame($secondMember->id, $draft->current_pool_member_id);

        $draft = app(ChangeDraftStatus::class)->handle($draft, $owner, DraftStatus::Paused);
        $this->assertSame(DraftStatus::Paused, $draft->status);
        $draft = app(ChangeDraftStatus::class)->handle($draft, $owner, DraftStatus::Active);
        $this->assertSame(DraftStatus::Active, $draft->status);
        $this->assertSame(2, AuditLog::query()->where('action', 'draft.status_changed')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'draft.order_confirmed']);
    }

    public function test_livewire_draft_requires_confirmation_before_the_transactional_pick(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $houseguest = Houseguest::factory()->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Confirmation', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        app(StartDraft::class)->handle($pool->draft, $owner);

        Livewire::actingAs($owner)
            ->test('pools.draft', ['pool' => $pool])
            ->call('confirmSelection', $houseguest->id)
            ->assertSet('showConfirmPickModal', true);

        $this->assertDatabaseCount('draft_picks', 0);

        Livewire::actingAs($owner)
            ->test('pools.draft', ['pool' => $pool])
            ->call('confirmSelection', $houseguest->id)
            ->call('pick')
            ->assertDispatched('draft-pick-made');

        $this->assertDatabaseCount('draft_picks', 1);
    }

    public function test_pool_manager_draft_correction_requires_a_reason_and_is_audited(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Correction', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        $pick = app(MakeDraftPick::class)->handle($draft, $pool->memberFor($owner), $houseguests[0]);

        try {
            app(CorrectDraftPick::class)->handle($pick, $houseguests[1], $owner, '');
            $this->fail('A correction reason should be mandatory.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_reason', $exception->errors());
        }

        try {
            app(CorrectDraftPick::class)->handle($pick, $houseguests[0], $owner, 'Aucun changement réel.');
            $this->fail('A correction should replace the current houseguest.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('houseguest', $exception->errors());
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'draft.pick_corrected']);

        app(CorrectDraftPick::class)->handle($pick, $houseguests[1], $owner, 'Erreur de sélection confirmée.');

        $this->assertSame($houseguests[1]->id, $pick->fresh()->houseguest_id);
        $audit = AuditLog::query()->where('action', 'draft.pick_corrected')->firstOrFail();
        $this->assertSame($houseguests[0]->id, $audit->metadata['from_houseguest_id']);
        $this->assertSame($houseguests[1]->id, $audit->metadata['to_houseguest_id']);
    }

    public function test_livewire_draft_correction_filters_exclusive_claims_and_surfaces_a_stale_replacement_error(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(4)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Correction exclusive', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 2,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $secondMember = app(JoinPool::class)->handle($secondUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        $ownerPick = app(MakeDraftPick::class)->handle($draft, $ownerMember, $houseguests[0]);
        app(MakeDraftPick::class)->handle($draft->fresh(), $secondMember, $houseguests[1]);

        Livewire::actingAs($owner)
            ->test('pools.draft', ['pool' => $pool])
            ->call('startCorrection', $ownerPick->id)
            ->assertSet('showCorrectionModal', true)
            ->assertSet('correctionHouseguestId', null)
            ->assertSet('correctionHouseguests', fn ($options): bool => $options->pluck('id')->intersect([
                $houseguests[0]->id,
                $houseguests[1]->id,
            ])->isEmpty())
            ->set('correctionHouseguestId', $houseguests[1]->id)
            ->set('correctionReason', 'La sélection est devenue indisponible.')
            ->call('correct')
            ->assertHasErrors(['correctionHouseguestId'])
            ->assertSet('showCorrectionModal', true);

        $this->assertSame($houseguests[0]->id, $ownerPick->fresh()->houseguest_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'draft.pick_corrected']);
    }

    public function test_completed_draft_rejects_action_and_direct_pick_mutations(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Repêchage final', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        $pick = app(MakeDraftPick::class)->handle($draft, $pool->memberFor($owner), $houseguests[0]);
        $this->assertSame(DraftStatus::Completed, $draft->fresh()->status);

        Livewire::actingAs($owner)
            ->test('pools.draft', ['pool' => $pool])
            ->assertDontSee('Corriger le choix de '.$houseguests[0]->name);

        try {
            app(CorrectDraftPick::class)->handle($pick, $houseguests[1], $owner, 'Correction tardive.');
            $this->fail('A completed draft should reject corrections.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft', $exception->errors());
        }

        try {
            $pick->update(['houseguest_id' => $houseguests[1]->id]);
            $this->fail('A completed draft pick should reject direct mutations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft', $exception->errors());
        }

        $this->assertSame($houseguests[0]->id, $pick->fresh()->houseguest_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'draft.pick_corrected']);
    }

    public function test_removed_member_cannot_make_a_pick_from_a_stale_draft_page(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Membre retiré', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        $removedMember = app(JoinPool::class)->handle($secondUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        app(MakeDraftPick::class)->handle($draft, $ownerMember, $houseguests[0]);

        $removedMember->newQuery()->whereKey($removedMember->id)->update([
            'status' => PoolMemberStatus::Removed,
            'removed_at' => now(),
        ]);

        try {
            app(MakeDraftPick::class)->handle($draft->fresh(), $removedMember, $houseguests[1]);
            $this->fail('A removed pool member should not be able to make a draft pick.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('houseguest', $exception->errors());
        }

        $this->assertDatabaseCount('draft_picks', 1);
    }
}
