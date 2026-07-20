<?php

namespace Tests\Feature;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Events\CreateRound;
use App\Enums\PoolMemberRole;
use App\Models\AuditLog;
use App\Models\Pool;
use App\Models\PoolMember;
use App\Models\Round;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CreateRoundTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_manager_creates_the_next_round_and_audit_atomically(): void
    {
        [$manager, $pool] = $this->managedPool();
        Round::factory()->for($pool)->create(['position' => 4]);

        $round = app(CreateRound::class)->handle($pool, $manager, [
            'name' => '  Nouvelle ronde  ',
            'starts_at' => '2026-08-01 18:00:00',
            'ends_at' => '2026-08-08 18:00:00',
        ]);

        $this->assertSame('Nouvelle ronde', $round->name);
        $this->assertSame(5, $round->position);
        $this->assertSame('draft', $round->status);
        $this->assertSame('2026-08-01 18:00:00', $round->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-08 18:00:00', $round->ends_at->format('Y-m-d H:i:s'));

        $auditLog = AuditLog::query()->where('action', 'round.created')->sole();
        $this->assertSame($pool->id, $auditLog->pool_id);
        $this->assertSame($manager->id, $auditLog->user_id);
        $this->assertSame($round->getMorphClass(), $auditLog->auditable_type);
        $this->assertSame($round->id, $auditLog->auditable_id);
    }

    public function test_round_creation_rolls_back_when_the_audit_cannot_be_written(): void
    {
        [$manager, $pool] = $this->managedPool();
        $recordAuditLog = $this->createMock(RecordAuditLog::class);
        $recordAuditLog->expects($this->once())
            ->method('handle')
            ->willThrowException(new RuntimeException('Audit storage unavailable.'));

        try {
            (new CreateRound($recordAuditLog))->handle($pool, $manager, [
                'name' => 'Ronde annulee',
                'starts_at' => null,
                'ends_at' => null,
            ]);
            $this->fail('The audit failure should abort round creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
        }

        $this->assertSame(0, $pool->rounds()->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_each_creation_reloads_the_locked_pool_before_assigning_the_next_position(): void
    {
        [$manager, $pool] = $this->managedPool();
        Round::factory()->for($pool)->create(['position' => 7]);
        $action = app(CreateRound::class);

        $first = $action->handle($pool, $manager, [
            'name' => 'Ronde huit',
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $second = $action->handle($pool, $manager, [
            'name' => 'Ronde neuf',
            'starts_at' => null,
            'ends_at' => null,
        ]);

        $this->assertSame(8, $first->position);
        $this->assertSame(9, $second->position);
        $this->assertSame(
            [7, 8, 9],
            $pool->rounds()->orderBy('position')->pluck('position')->all(),
        );
        $this->assertSame(2, AuditLog::query()->where('action', 'round.created')->count());
    }

    /** @return array{User, Pool, PoolMember} */
    private function managedPool(): array
    {
        $manager = User::factory()->create();
        $pool = Pool::factory()->for($manager, 'owner')->create();
        $member = PoolMember::factory()->for($pool)->for($manager)->create([
            'role' => PoolMemberRole::Owner,
            'draft_position' => 1,
        ]);

        return [$manager, $pool, $member];
    }
}
