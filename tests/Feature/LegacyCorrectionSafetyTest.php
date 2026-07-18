<?php

namespace Tests\Feature;

use App\Actions\Weeks\RecordWeekOutcome;
use App\Actions\Weeks\WeekPhaseManager;
use App\Models\AuditLog;
use App\Models\Houseguest;
use App\Models\Season;
use App\Models\User;
use App\Models\Week;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyCorrectionSafetyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_correcting_an_eviction_rebuilds_activity_and_records_immutable_history(): void
    {
        $season = Season::factory()->create();
        $week = Week::factory()->for($season)->create();
        $administrator = User::factory()->admin()->create();
        $first = Houseguest::factory()->for($season)->create();
        $second = Houseguest::factory()->for($season)->create();
        $action = app(RecordWeekOutcome::class);

        $action->handle($week, $administrator, $this->evictionPayload($first->id));
        $this->assertFalse($first->fresh()->is_active);

        $action->handle($week, $administrator, $this->evictionPayload($second->id), 'Broadcast correction');

        $this->assertTrue($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertSame('Broadcast correction', AuditLog::query()
            ->where('action', 'week.outcome_corrected')
            ->firstOrFail()
            ->metadata['correction_reason']);
    }

    public function test_correction_without_reason_is_rejected(): void
    {
        $season = Season::factory()->create();
        $week = Week::factory()->for($season)->create();
        $administrator = User::factory()->admin()->create();
        $houseguests = Houseguest::factory()->for($season)->count(2)->create();
        $action = app(RecordWeekOutcome::class);
        $action->handle($week, $administrator, $this->evictionPayload($houseguests[0]->id));

        $this->expectException(ValidationException::class);
        $action->handle($week, $administrator, $this->evictionPayload($houseguests[1]->id));
    }

    /** @return list<array<string, mixed>> */
    private function evictionPayload(int $houseguestId): array
    {
        return [[
            'position' => 1,
            'type' => WeekPhaseManager::TYPE_EVICTIONS,
            'evicted_ids' => [$houseguestId],
        ]];
    }
}
