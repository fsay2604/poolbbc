<?php

namespace Tests\Feature;

use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Drafts\StartDraft;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\CreateSeasonRoundFromTemplate;
use App\Actions\Events\PublishSeasonEventResult;
use App\Actions\Events\SynchronizeEventLifecycle;
use App\Actions\Events\TransitionSeasonEvent;
use App\Actions\Pools\ActivatePool;
use App\Actions\Pools\CreatePool;
use App\Actions\Pools\OpenPoolRegistration;
use App\Actions\Pools\TransitionPoolStatus;
use App\Enums\EventStatus;
use App\Enums\OfficialRoundTemplate;
use App\Enums\PoolStatus;
use App\Models\AuditLog;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class AdministrativeAuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_season_administration_records_create_update_deactivation_and_delete_snapshots(): void
    {
        $administrator = User::factory()->admin()->create();
        $previousSeason = Season::factory()->create(['is_active' => true]);

        $component = Livewire::actingAs($administrator)
            ->test('admin.seasons.index')
            ->set('form.name', 'Saison auditée')
            ->set('form.is_active', true)
            ->set('form.starts_on', '2026-07-01')
            ->set('form.ends_on', '2026-09-30')
            ->call('save')
            ->assertHasNoErrors();

        $season = Season::query()->where('name', 'Saison auditée')->firstOrFail();
        $createdAudit = $this->auditFor('season.created', $season, $administrator);
        $this->assertNull(data_get($createdAudit->metadata, 'before'));
        $this->assertSame('Saison auditée', data_get($createdAudit->metadata, 'after.name'));
        $this->assertTrue(data_get($createdAudit->metadata, 'after.is_active'));

        $deactivatedAudit = $this->auditFor('season.deactivated', $previousSeason, $administrator);
        $this->assertTrue(data_get($deactivatedAudit->metadata, 'before.is_active'));
        $this->assertFalse(data_get($deactivatedAudit->metadata, 'after.is_active'));

        $component
            ->call('edit', $season->id)
            ->set('form.name', 'Saison renommée')
            ->call('save')
            ->assertHasNoErrors();

        $updatedAudit = $this->auditFor('season.updated', $season, $administrator);
        $this->assertSame('Saison auditée', data_get($updatedAudit->metadata, 'before.name'));
        $this->assertSame('Saison renommée', data_get($updatedAudit->metadata, 'after.name'));

        $component->call('delete', $season->id)->assertHasNoErrors();

        $deletedAudit = $this->auditFor('season.deleted', $season, $administrator);
        $this->assertSame('Saison renommée', data_get($deletedAudit->metadata, 'before.name'));
        $this->assertNull(data_get($deletedAudit->metadata, 'after'));
        $this->assertModelMissing($season);
    }

    public function test_houseguest_administration_records_create_update_and_delete_snapshots(): void
    {
        $administrator = User::factory()->admin()->create();
        Season::factory()->create(['is_active' => true]);

        $component = Livewire::actingAs($administrator)
            ->test('admin.houseguests.index')
            ->set('form.name', 'Célébrité auditée')
            ->set('form.sex', 'F')
            ->set('form.occupations', [])
            ->set('form.is_active', true)
            ->set('form.sort_order', 4)
            ->call('save')
            ->assertHasNoErrors();

        $houseguest = Houseguest::query()->where('name', 'Célébrité auditée')->firstOrFail();
        $createdAudit = $this->auditFor('houseguest.created', $houseguest, $administrator);
        $this->assertNull(data_get($createdAudit->metadata, 'before'));
        $this->assertSame('Célébrité auditée', data_get($createdAudit->metadata, 'after.name'));

        $component
            ->call('edit', $houseguest->id)
            ->set('form.name', 'Célébrité renommée')
            ->call('save')
            ->assertHasNoErrors();

        $updatedAudit = $this->auditFor('houseguest.updated', $houseguest, $administrator);
        $this->assertSame('Célébrité auditée', data_get($updatedAudit->metadata, 'before.name'));
        $this->assertSame('Célébrité renommée', data_get($updatedAudit->metadata, 'after.name'));

        $component
            ->call('confirmDelete', $houseguest->id)
            ->call('deleteSelectedHouseguest')
            ->assertDispatched('houseguest-deleted');

        $deletedAudit = $this->auditFor('houseguest.deleted', $houseguest, $administrator);
        $this->assertSame('Célébrité renommée', data_get($deletedAudit->metadata, 'before.name'));
        $this->assertNull(data_get($deletedAudit->metadata, 'after'));
        $this->assertModelMissing($houseguest);
    }

    public function test_user_administration_records_safe_crud_role_and_password_audits(): void
    {
        $administrator = User::factory()->admin()->create();

        $component = Livewire::actingAs($administrator)
            ->test('admin.users.index')
            ->set('form.name', 'Compte audité')
            ->set('form.email', 'audit@example.com')
            ->set('form.is_admin', false)
            ->set('form.password', 'secret-password-123')
            ->set('form.password_confirmation', 'secret-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'audit@example.com')->firstOrFail();
        $createdAudit = $this->auditFor('user.created', $user, $administrator);
        $this->assertSame('audit@example.com', data_get($createdAudit->metadata, 'after.email'));
        $this->assertStringNotContainsString('password', json_encode($createdAudit->metadata, JSON_THROW_ON_ERROR));

        $component
            ->call('edit', $user->id)
            ->set('form.name', 'Compte renommé')
            ->call('save')
            ->assertHasNoErrors();

        $updatedAudit = $this->auditFor('user.updated', $user, $administrator);
        $this->assertSame('Compte audité', data_get($updatedAudit->metadata, 'before.name'));
        $this->assertSame('Compte renommé', data_get($updatedAudit->metadata, 'after.name'));

        $component->call('toggleAdmin', $user->id)->assertHasNoErrors();
        $roleAudit = $this->auditFor('user.admin_status_changed', $user, $administrator);
        $this->assertFalse(data_get($roleAudit->metadata, 'before.is_admin'));
        $this->assertTrue(data_get($roleAudit->metadata, 'after.is_admin'));

        $component
            ->call('confirmResetPassword', $user->id)
            ->set('resetPasswordForm.password', 'another-secret-123')
            ->set('resetPasswordForm.password_confirmation', 'another-secret-123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $passwordAudit = $this->auditFor('user.password_reset', $user, $administrator);
        $this->assertSame(['credential' => 'password'], $passwordAudit->metadata);
        $this->assertStringNotContainsString('another-secret-123', json_encode($passwordAudit->metadata, JSON_THROW_ON_ERROR));

        $component
            ->call('confirmDelete', $user->id)
            ->call('deleteSelectedUser')
            ->assertDispatched('user-deleted');

        $deletedAudit = $this->auditFor('user.deleted', $user, $administrator);
        $this->assertSame('Compte renommé', data_get($deletedAudit->metadata, 'before.name'));
        $this->assertNotNull(data_get($deletedAudit->metadata, 'after.deleted_at'));
        $this->assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
    }

    public function test_official_round_event_and_result_administration_records_actor_and_snapshots(): void
    {
        $administrator = User::factory()->admin()->create();
        $season = Season::factory()->create();
        Houseguest::factory()->for($season)->create(['name' => 'Alex']);
        $round = app(CreateSeasonRoundFromTemplate::class)->handle(
            $season,
            $administrator,
            OfficialRoundTemplate::Finale,
            'Finale auditée',
            now()->addHour(),
            now()->addDay(),
        );

        $roundAudit = $this->auditFor('season_round.created', $round, $administrator);
        $this->assertSame($season->id, data_get($roundAudit->metadata, 'season_id'));
        $this->assertSame(OfficialRoundTemplate::Finale->value, data_get($roundAudit->metadata, 'template'));

        $event = $round->events()->firstOrFail();
        Livewire::actingAs($administrator)
            ->test('admin.official-rounds')
            ->call('startEdit', $event->id)
            ->set('eventForm.name', 'Finale renommée')
            ->call('saveEvent')
            ->assertHasNoErrors();

        $updatedAudit = $this->auditFor('season_event.updated', $event, $administrator);
        $this->assertSame($event->name, data_get($updatedAudit->metadata, 'before.name'));
        $this->assertSame('Finale renommée', data_get($updatedAudit->metadata, 'after.name'));

        Carbon::setTestNow($event->fresh()->opens_at);
        try {
            $event = app(TransitionSeasonEvent::class)->transition($event->fresh(), EventStatus::Open, $administrator);
            $event = app(TransitionSeasonEvent::class)->transition($event, EventStatus::Locked, $administrator);
            $statusAudits = AuditLog::query()
                ->where('action', 'season_event.status_changed')
                ->where('auditable_type', $event->getMorphClass())
                ->where('auditable_id', $event->id)
                ->orderBy('id')
                ->get();
            $this->assertSame(['open', 'locked'], $statusAudits->pluck('metadata')->map(fn (array $metadata): string => $metadata['to'])->all());
            $this->assertTrue($statusAudits->every(fn (AuditLog $audit): bool => $audit->user_id === $administrator->id));

            Bus::fake();
            $option = $event->options()->firstOrFail();
            $result = app(PublishSeasonEventResult::class)->handle($event, $administrator, [$option->id]);
            $resultAudit = $this->auditFor('season_event.result_scoring_started', $result, $administrator);
            $this->assertSame(1, data_get($resultAudit->metadata, 'version'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pool_round_event_and_status_mutations_keep_a_complete_audit_trail(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id,
            'name' => 'Pool audité',
            'description' => null,
            'timezone' => 'America/Toronto',
            'competition_mode' => 'prediction_only',
            'max_members' => 4,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);
        $this->auditFor('pool.created', $pool, $owner);

        $pool = app(OpenPoolRegistration::class)->handle($pool, $owner);
        $registrationAudit = $this->auditFor('pool.registrations_opened', $pool, $owner);
        $this->assertSame('configuration', data_get($registrationAudit->metadata, 'before.status'));
        $this->assertSame('registration', data_get($registrationAudit->metadata, 'after.status'));

        $pool = app(ActivatePool::class)->handle($pool, $owner);
        $activationAudit = $this->auditFor('pool.activated', $pool, $owner);
        $this->assertSame('registration', data_get($activationAudit->metadata, 'before.status'));
        $this->assertSame('active', data_get($activationAudit->metadata, 'after.status'));

        Livewire::actingAs($owner)
            ->test('pools.events', ['pool' => $pool])
            ->set('roundForm.name', 'Ronde auditée')
            ->call('createRound')
            ->assertHasNoErrors();

        $round = $pool->rounds()->where('name', 'Ronde auditée')->firstOrFail();
        $this->auditFor('round.created', $round, $owner);
        $event = app(CreateEvent::class)->handle($pool, $owner, [
            'round_id' => $round->id,
            'name' => 'Événement audité',
            'mode' => 'prediction',
            'answer_source' => 'boolean',
            'locks_at' => now()->addHour(),
        ]);
        $this->auditFor('event.created', $event, $owner);

        $event = app(SynchronizeEventLifecycle::class)->transition($event, EventStatus::Open, $owner);
        app(SynchronizeEventLifecycle::class)->transition($event, EventStatus::Cancelled, $owner, 'Décision auditée.');
        $statusAudit = AuditLog::query()
            ->where('action', 'event.status_changed')
            ->where('auditable_id', $event->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('cancelled', data_get($statusAudit->metadata, 'to'));
        $this->assertSame('Décision auditée.', data_get($statusAudit->metadata, 'reason'));

        $pool = app(TransitionPoolStatus::class)->handle($pool, $owner, PoolStatus::Completed);
        app(TransitionPoolStatus::class)->handle($pool, $owner, PoolStatus::Archived);
        $poolStatusAudits = AuditLog::query()
            ->where('action', 'pool.status_changed')
            ->where('auditable_id', $pool->id)
            ->orderBy('id')
            ->get();
        $this->assertSame(['completed', 'archived'], $poolStatusAudits->pluck('metadata')->map(fn (array $metadata): string => $metadata['to'])->all());
        $this->assertSame('active', data_get($poolStatusAudits->first()->metadata, 'before.status'));
        $this->assertSame('completed', data_get($poolStatusAudits->first()->metadata, 'after.status'));
        $this->assertTrue($poolStatusAudits->every(fn (AuditLog $audit): bool => $audit->user_id === $owner->id));
    }

    public function test_completing_a_draft_records_the_member_actor_and_both_status_changes(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $houseguest = Houseguest::factory()->for($season)->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id,
            'name' => 'Repêchage audité',
            'description' => null,
            'timezone' => 'America/Toronto',
            'competition_mode' => 'roster_only',
            'max_members' => 1,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);
        $pool = app(OpenPoolRegistration::class)->handle($pool, $owner);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);

        app(MakeDraftPick::class)->handle($draft, $pool->memberFor($owner), $houseguest);

        $audit = $this->auditFor('draft.completed', $draft, $owner);
        $this->assertSame('active', data_get($audit->metadata, 'before.draft.status'));
        $this->assertSame('completed', data_get($audit->metadata, 'after.draft.status'));
        $this->assertSame('draft', data_get($audit->metadata, 'before.pool.status'));
        $this->assertSame('active', data_get($audit->metadata, 'after.pool.status'));
    }

    private function auditFor(string $action, Model $auditable, User $actor): AuditLog
    {
        return AuditLog::query()
            ->where('action', $action)
            ->where('user_id', $actor->id)
            ->where('auditable_type', $auditable->getMorphClass())
            ->where('auditable_id', $auditable->getKey())
            ->sole();
    }
}
