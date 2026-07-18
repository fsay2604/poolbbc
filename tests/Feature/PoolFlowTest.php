<?php

namespace Tests\Feature;

use App\Actions\Drafts\ChangeDraftStatus;
use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Drafts\StartDraft;
use App\Actions\Pools\CreatePool;
use App\Actions\Pools\JoinPool;
use App\Actions\Pools\OpenPoolRegistration;
use App\Actions\Pools\PreviewPoolInvitation;
use App\Actions\Pools\RemovePoolMember;
use App\Actions\Pools\TransitionPoolStatus;
use App\Actions\Predictions\SubmitEventPrediction;
use App\Enums\DraftStatus;
use App\Enums\EventStatus;
use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Models\AuditLog;
use App\Models\Draft;
use App\Models\Event;
use App\Models\EventOption;
use App\Models\Houseguest;
use App\Models\Pool;
use App\Models\PoolEvent;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonEvent;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\TestCase;

class PoolFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_pool_stays_in_configuration_until_its_manager_opens_registrations(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = app(CreatePool::class)->handle($owner, [
            'season_id' => $season->id,
            'name' => 'Configuration explicite',
            'description' => null,
            'timezone' => 'America/Toronto',
            'max_members' => 4,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $this->assertSame(PoolStatus::Configuration, $pool->status);

        try {
            app(PreviewPoolInvitation::class)->handle(User::factory()->create(), $pool->invite_code);
            $this->fail('A configuration pool should not accept invitations yet.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('joinForm.invite_code', $exception->errors());
        }

        $pool = app(OpenPoolRegistration::class)->handle($pool, $owner);

        $this->assertSame(PoolStatus::Registration, $pool->status);
        $this->assertDatabaseHas('audit_logs', [
            'pool_id' => $pool->id,
            'action' => 'pool.registrations_opened',
        ]);
    }

    public function test_owner_can_create_a_pool_and_another_user_can_join_with_the_code(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $season = Season::factory()->create();

        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id,
            'name' => 'Pool du dimanche',
            'description' => 'Entre amis',
            'timezone' => 'America/Toronto',
            'max_members' => 4,
            'picks_per_member' => 2,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $ownerMembership = $pool->members()->whereBelongsTo($owner)->firstOrFail();
        $this->assertSame(PoolMemberRole::Owner, $ownerMembership->role);
        $this->assertNotNull($pool->draft);
        $this->assertSame(8, mb_strlen($pool->invite_code));

        $membership = app(JoinPool::class)->handle($member, mb_strtolower($pool->invite_code));

        $this->assertSame($pool->id, $membership->pool_id);
        $this->assertSame(2, $membership->draft_position);
        $this->assertTrue(Gate::forUser($member)->allows('view', $pool));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', $pool));
        $systemAdministrator = User::factory()->admin()->create();
        $this->assertFalse(Gate::forUser($systemAdministrator)->allows('view', $pool));
        $this->assertFalse(Gate::forUser($systemAdministrator)->allows('update', $pool));
        $this->assertFalse(Gate::forUser($systemAdministrator)->allows('view', $pool->draft));
    }

    public function test_a_pool_rejects_members_after_reaching_capacity(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id,
            'name' => 'Pool complet',
            'description' => null,
            'timezone' => 'America/Toronto',
            'max_members' => 1,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
    }

    public function test_owner_can_remove_a_member_before_the_draft_and_positions_are_reordered(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id,
            'name' => 'Pool avec remplacement',
            'description' => null,
            'timezone' => 'America/Toronto',
            'max_members' => 4,
            'picks_per_member' => 1,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $removedMember = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
        $remainingMember = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);

        app(RemovePoolMember::class)->handle($pool, $removedMember, $owner, 'Le membre a demandé son retrait.');

        $this->assertSame(PoolMemberStatus::Removed, $removedMember->fresh()->status);
        $this->assertNull($removedMember->fresh()->draft_position);
        $this->assertSame(2, $remainingMember->fresh()->draft_position);
        $this->assertDatabaseHas('audit_logs', [
            'pool_id' => $pool->id,
            'user_id' => $owner->id,
            'action' => 'pool.member_removed',
        ]);
        $this->assertSame(
            'Le membre a demandé son retrait.',
            AuditLog::query()->where('action', 'pool.member_removed')->latest('id')->firstOrFail()->metadata['reason'],
        );
    }

    public function test_removed_pool_administrator_immediately_loses_management_permissions(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Droits révoqués', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 3, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $administrator = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
        $administrator->update(['role' => PoolMemberRole::Administrator]);

        $this->assertTrue(Gate::forUser($administrator->user)->allows('update', $pool));

        app(RemovePoolMember::class)->handle($pool, $administrator, $owner, 'Révocation des droits administratifs.');

        $this->assertFalse(Gate::forUser($administrator->user)->allows('update', $pool->fresh()));
    }

    public function test_member_removal_is_blocked_while_the_draft_is_in_progress(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Repêchage protégé', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $departingUser = User::factory()->create();
        $member = app(JoinPool::class)->handle($departingUser, $pool->invite_code);
        app(StartDraft::class)->handle($pool->draft, $owner);

        $assertRemovalIsBlocked = function () use ($pool, $member, $owner): void {
            try {
                app(RemovePoolMember::class)->handle($pool, $member, $owner, 'Départ demandé par le membre.');
                $this->fail('A member should not be removable while the draft is active or paused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('member', $exception->errors());
            }
        };

        $assertRemovalIsBlocked();
        app(ChangeDraftStatus::class)->handle($pool->draft->fresh(), $owner, DraftStatus::Paused);
        $assertRemovalIsBlocked();

        $this->assertSame(PoolMemberStatus::Active, $member->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'pool_id' => $pool->id,
            'action' => 'pool.member_removed',
        ]);
    }

    public function test_removal_requires_a_reason_and_the_owner_cannot_leave(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Départs contrôlés', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $member = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);

        try {
            app(RemovePoolMember::class)->handle($pool, $member, $owner, '  ');
            $this->fail('A departure reason should be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $ownerRemovalWasDenied = false;
        try {
            app(RemovePoolMember::class)->handle($pool, $ownerMember, $owner, 'Le propriétaire voudrait partir.');
            $this->fail('The pool owner should not be removable.');
        } catch (AuthorizationException) {
            $ownerRemovalWasDenied = true;
        }

        $this->assertTrue($ownerRemovalWasDenied);
        $this->assertSame(PoolMemberStatus::Active, $member->fresh()->status);
        $this->assertSame(PoolMemberRole::Owner, $ownerMember->fresh()->role);
        $this->assertDatabaseMissing('audit_logs', [
            'pool_id' => $pool->id,
            'action' => 'pool.member_removed',
        ]);
    }

    public function test_pool_member_policy_supports_manager_removal_and_member_departure(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => Season::factory()->create()->id, 'name' => 'Autorisations de départ', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $member = app(JoinPool::class)->handle($memberUser, $pool->invite_code);
        $ownerMember = $pool->memberFor($owner);

        $this->assertTrue(Gate::forUser($owner)->allows('remove', $member));
        $this->assertTrue(Gate::forUser($memberUser)->allows('leave', $member));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('remove', $member));
        $this->assertFalse(Gate::forUser($owner)->allows('leave', $ownerMember));
    }

    public function test_member_departure_modal_requires_and_records_a_reason(): void
    {
        $owner = User::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => Season::factory()->create()->id, 'name' => 'Départ avec justification', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $member = app(JoinPool::class)->handle(User::factory()->create(), $pool->invite_code);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool])
            ->call('startMemberRemoval', $member->id)
            ->assertSet('showRemoveMemberModal', true)
            ->assertSet('removingMemberId', $member->id)
            ->call('removeMember')
            ->assertHasErrors(['memberRemovalReason' => 'required'])
            ->set('memberRemovalReason', 'Départ confirmé avec le membre.')
            ->call('removeMember')
            ->assertHasNoErrors()
            ->assertSet('showRemoveMemberModal', false)
            ->assertDispatched('member-removed');

        $this->assertSame(PoolMemberStatus::Removed, $member->fresh()->status);
        $this->assertSame(
            'Départ confirmé avec le membre.',
            AuditLog::query()->where('action', 'pool.member_removed')->latest('id')->firstOrFail()->metadata['reason'],
        );
    }

    public function test_member_can_leave_idempotently_after_the_draft_is_completed(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $houseguests = Houseguest::factory()->count(2)->for($season)->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Équipes conservées', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 2, 'picks_per_member' => 1,
            'draft_mode' => 'linear', 'exclusive_draft' => true,
        ]);
        $departingUser = User::factory()->create();
        $member = app(JoinPool::class)->handle($departingUser, $pool->invite_code);
        $draft = app(StartDraft::class)->handle($pool->draft, $owner);
        app(MakeDraftPick::class)->handle($draft, $pool->memberFor($owner), $houseguests[0]);
        app(MakeDraftPick::class)->handle($draft->fresh(), $member, $houseguests[1]);

        $draftPosition = $member->draft_position;
        $reason = 'Le membre quitte le pool pour des raisons personnelles.';
        app(RemovePoolMember::class)->handle($pool, $member, $departingUser, $reason);
        $removedAt = $member->fresh()->removed_at;
        app(RemovePoolMember::class)->handle($pool, $member, $departingUser, 'Second appel sans nouvel effet.');

        $this->assertSame(PoolMemberStatus::Removed, $member->fresh()->status);
        $this->assertTrue($removedAt->equalTo($member->fresh()->removed_at));
        $this->assertSame($draftPosition, $member->fresh()->draft_position);
        $this->assertTrue($member->draftPicks()->where('houseguest_id', $houseguests[1]->id)->exists());
        $this->assertTrue($pool->competitionMembers()->whereKey($member->id)->exists());

        $removalLogs = AuditLog::query()
            ->where('pool_id', $pool->id)
            ->where('action', 'pool.member_left');
        $this->assertSame(1, $removalLogs->count());
        $metadata = $removalLogs->firstOrFail()->metadata;
        $this->assertSame($departingUser->id, $removalLogs->firstOrFail()->user_id);
        $this->assertSame($reason, $metadata['reason']);
        $this->assertTrue($metadata['team_preserved']);
    }

    public function test_removed_member_cannot_preview_or_rejoin_the_pool(): void
    {
        $owner = User::factory()->create();
        $removedUser = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id, 'name' => 'Retrait définitif', 'description' => null,
            'timezone' => 'America/Toronto', 'max_members' => 3, 'picks_per_member' => 1,
            'draft_mode' => 'snake', 'exclusive_draft' => true,
        ]);
        $member = app(JoinPool::class)->handle($removedUser, $pool->invite_code);
        app(RemovePoolMember::class)->handle($pool, $member, $owner, 'Le membre quitte définitivement ce pool.');

        foreach ([
            fn () => app(PreviewPoolInvitation::class)->handle($removedUser, $pool->invite_code),
            fn () => app(JoinPool::class)->handle($removedUser, $pool->invite_code),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A removed member should not be allowed to rejoin.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('joinForm.invite_code', $exception->errors());
            }
        }

        $this->assertSame(PoolMemberStatus::Removed, $member->fresh()->status);
        $this->assertSame(1, $pool->members()->whereBelongsTo($removedUser)->count());
    }

    public function test_pool_can_only_complete_after_all_events_are_terminal_and_then_be_archived_read_only(): void
    {
        $owner = User::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        $member = PoolMember::factory()->for($pool)->for($owner)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);
        $round = Round::factory()->for($pool)->create();
        $event = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => EventStatus::Open,
            'position' => 1,
        ]);
        $staleEvent = Event::factory()->for($round)->create([
            'pool_id' => $pool->id,
            'created_by' => $owner->id,
            'status' => EventStatus::Draft,
            'position' => 2,
        ]);
        $staleOption = EventOption::factory()->for($staleEvent)->create();
        $staleEvent->update(['status' => EventStatus::Open]);
        $staleEventSnapshot = clone $staleEvent;

        try {
            app(TransitionPoolStatus::class)->handle($pool, $owner, PoolStatus::Completed);
            $this->fail('A pool with an unresolved event must not complete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pool', $exception->errors());
        }

        $event->update(['status' => EventStatus::Cancelled]);
        $staleEvent->update(['status' => EventStatus::Cancelled]);
        $pool = app(TransitionPoolStatus::class)->handle($pool, $owner, PoolStatus::Completed);
        $this->assertSame(PoolStatus::Completed, $pool->status);
        $this->assertFalse(Gate::forUser($owner)->allows('update', $pool));
        $this->assertTrue(Gate::forUser($owner)->allows('archive', $pool));

        try {
            app(SubmitEventPrediction::class)->handle($staleEventSnapshot, $member, [$staleOption->id]);
            $this->fail('A completed pool must reject stale prediction submissions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prediction', $exception->errors());
        }
        $this->assertDatabaseMissing('event_predictions', [
            'event_id' => $staleEvent->id,
            'pool_member_id' => $member->id,
        ]);

        try {
            Round::factory()->for($pool)->create();
            $this->fail('A completed pool must reject new child records.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pool', $exception->errors());
        }

        try {
            $pool->update(['name' => 'Mutation interdite']);
            $this->fail('A completed pool must be read-only.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pool', $exception->errors());
        }

        Event::query()->whereKey($staleEvent->id)->update(['status' => EventStatus::Open->value]);
        try {
            app(TransitionPoolStatus::class)->handle($pool, $owner, PoolStatus::Archived);
            $this->fail('Archiving must revalidate that no unresolved child event appeared.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pool', $exception->errors());
        }
        Event::query()->whereKey($staleEvent->id)->update(['status' => EventStatus::Cancelled->value]);

        $pool = app(TransitionPoolStatus::class)->handle($pool, $owner, PoolStatus::Archived);

        $this->assertSame(PoolStatus::Archived, $pool->status);
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $pool));
        try {
            $pool->delete();
            $this->fail('A pool with history must not be deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pool', $exception->errors());
        }
        $statusChanges = AuditLog::query()
            ->where('pool_id', $pool->id)
            ->where('action', 'pool.status_changed');
        $this->assertSame(2, $statusChanges->count());
        $archiveMetadata = $statusChanges->latest('id')->firstOrFail()->metadata;
        $this->assertSame(PoolStatus::Completed->value, $archiveMetadata['from']);
        $this->assertSame(PoolStatus::Archived->value, $archiveMetadata['to']);
    }

    public function test_terminal_pool_dashboard_is_historical_and_ignores_published_future_deadlines(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
            'status' => PoolStatus::Active,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create([
            'role' => PoolMemberRole::Owner,
        ]);
        $round = SeasonRound::factory()->for($season)->create();
        $publishedEvent = SeasonEvent::factory()->for($round, 'round')->create([
            'name' => 'Événement déjà publié',
            'status' => EventStatus::Published,
            'locks_at' => now()->addDay(),
        ]);
        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $publishedEvent->id,
        ]);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool])
            ->assertSet('summary.next_deadline', null)
            ->assertSet('summary.next_event', null);

        Pool::query()->whereKey($pool->id)->update(['status' => PoolStatus::Completed->value]);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool->fresh()])
            ->assertSet('summary.next_deadline', null)
            ->assertSet('summary.next_event', null)
            ->assertSee('Historique en lecture seule')
            ->assertDontSee('Continuer')
            ->assertDontSee('Invitation')
            ->assertDontSee($pool->invite_code);
    }

    public function test_pool_livewire_flows_render_for_an_authorized_member(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $this->actingAs($owner);

        Livewire::test('pools.index')
            ->set('form.season_id', $season->id)
            ->set('form.name', 'Pool Livewire')
            ->call('create')
            ->assertHasNoErrors();

        $pool = $owner->ownedPools()->where('name', 'Pool Livewire')->firstOrFail();
        $this->assertSame([], $pool->scoring_config);

        $this->get(route('pools.show', $pool))->assertOk();
        $this->get(route('pools.draft', $pool))->assertOk();
        $this->get(route('pools.events', $pool))->assertOk();
        $this->get(route('pools.leaderboard', $pool))->assertOk();
    }

    public function test_removed_member_loses_every_pool_route_on_the_next_livewire_request(): void
    {
        $owner = User::factory()->create();
        $memberUser = User::factory()->create();
        $pool = Pool::factory()->create(['owner_id' => $owner->id]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);
        $membership = PoolMember::factory()->for($pool)->for($memberUser)->create(['draft_position' => 2]);
        Draft::factory()->for($pool)->create();

        foreach (['pools.show', 'pools.draft', 'pools.predictions', 'pools.events', 'pools.leaderboard'] as $routeName) {
            $membership->update([
                'status' => PoolMemberStatus::Active,
                'removed_at' => null,
            ]);
            $response = $this->actingAs($memberUser)->get(route($routeName, $pool))->assertOk();
            $snapshot = Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot');

            $membership->update([
                'status' => PoolMemberStatus::Removed,
                'removed_at' => now(),
            ]);

            $this->withHeader('X-Livewire', 'true')
                ->postJson(app('livewire')->getUpdateUri(), [
                    'components' => [[
                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                        'updates' => [],
                        'calls' => [],
                    ]],
                ])
                ->assertForbidden();
        }
    }

    public function test_pool_dashboard_counts_only_active_official_pool_events_and_their_distinct_rounds(): void
    {
        $owner = User::factory()->create();
        $season = Season::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create([
            'season_id' => $season->id,
            'owner_id' => $owner->id,
        ]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);

        foreach ([2, 3, 2] as $position => $eventCount) {
            $round = SeasonRound::factory()->for($season)->create(['position' => $position + 1]);

            SeasonEvent::factory()->for($round, 'round')->count($eventCount)->create()
                ->each(fn (SeasonEvent $event) => PoolEvent::factory()->create([
                    'pool_id' => $pool->id,
                    'season_event_id' => $event->id,
                ]));
        }

        $inactiveRound = SeasonRound::factory()->for($season)->create(['position' => 4]);
        $inactiveEvent = SeasonEvent::factory()->for($inactiveRound, 'round')->create();
        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => $inactiveEvent->id,
            'is_active' => false,
        ]);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool])
            ->assertSet('pool.rounds_count', 3)
            ->assertSet('pool.events_count', 7);
    }

    public function test_pool_dashboard_local_counts_require_an_active_canonical_pool_event(): void
    {
        $owner = User::factory()->create();
        $pool = Pool::factory()->predictionOnly()->create(['owner_id' => $owner->id]);
        PoolMember::factory()->for($pool)->for($owner)->create(['role' => PoolMemberRole::Owner]);

        foreach ([2, 1] as $position => $eventCount) {
            $round = Round::factory()->for($pool)->create(['position' => $position + 1]);

            foreach (range(1, $eventCount) as $eventPosition) {
                $event = Event::factory()->for($round)->create(['position' => $eventPosition]);
                PoolEvent::factory()->create([
                    'pool_id' => $pool->id,
                    'season_event_id' => null,
                    'local_event_id' => $event->id,
                ]);
            }
        }

        $irrelevantRound = Round::factory()->for($pool)->create(['position' => 3]);
        Event::factory()->for($irrelevantRound)->create();
        $inactiveEvent = Event::factory()->for($irrelevantRound)->create(['position' => 2]);
        PoolEvent::factory()->create([
            'pool_id' => $pool->id,
            'season_event_id' => null,
            'local_event_id' => $inactiveEvent->id,
            'is_active' => false,
        ]);

        Livewire::actingAs($owner)
            ->test('pools.show', ['pool' => $pool])
            ->assertSet('pool.rounds_count', 2)
            ->assertSet('pool.events_count', 3);
    }

    public function test_invited_user_reviews_pool_rules_before_explicitly_accepting(): void
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $season = Season::factory()->create();
        $pool = $this->createPoolOpenForRegistration($owner, [
            'season_id' => $season->id,
            'name' => 'Pool à examiner',
            'description' => 'Règles visibles avant adhésion.',
            'timezone' => 'America/Toronto',
            'competition_mode' => 'hybrid',
            'max_members' => 4,
            'picks_per_member' => 2,
            'draft_mode' => 'snake',
            'exclusive_draft' => true,
        ]);

        $component = Livewire::actingAs($invitee)
            ->test('pools.index')
            ->set('joinForm.invite_code', mb_strtolower($pool->invite_code))
            ->call('join')
            ->assertHasErrors(['joinForm.invite_code'])
            ->call('previewJoin')
            ->assertHasNoErrors()
            ->assertSee('Pool à examiner')
            ->assertSee('Règles visibles avant adhésion.')
            ->assertSee('Accepter et rejoindre');

        $this->assertNull($pool->memberFor($invitee));

        $component->call('join')->assertHasNoErrors()->assertDispatched('pool-joined');

        $this->assertNotNull($pool->memberFor($invitee));
    }
}
