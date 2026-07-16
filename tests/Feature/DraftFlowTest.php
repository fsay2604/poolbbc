<?php

namespace Tests\Feature;

use App\Actions\Drafts\MakeDraftPick;
use App\Actions\Drafts\StartDraft;
use App\Actions\Pools\CreatePool;
use App\Actions\Pools\JoinPool;
use App\Enums\DraftStatus;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        $pool = app(CreatePool::class)->handle($owner, [
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
        $pool = app(CreatePool::class)->handle($owner, [
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
}
