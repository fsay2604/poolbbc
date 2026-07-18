<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventOption;
use App\Models\EventResult;
use App\Models\PointEntry;
use App\Models\SeasonEvent;
use App\Models\SeasonEventOption;
use App\Models\SeasonEventResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class CanonicalLedgerImmutabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_canonical_ledger_trigger_migration_recovers_from_partial_ddl(): void
    {
        DB::statement('DROP TRIGGER point_entries_append_only_update');

        $migration = require database_path('migrations/2026_07_17_044731_enforce_canonical_ledger_immutability.php');
        $migration->up();

        $entry = PointEntry::factory()->create(['points' => 4]);

        try {
            DB::table('point_entries')->where('id', $entry->id)->update(['points' => 40]);
            $this->fail('A recovered append-only trigger must reject bulk updates.');
        } catch (QueryException) {
            $this->assertSame(4, $entry->fresh()->points);
        }
    }

    public function test_local_result_publication_is_the_only_allowed_update_and_freezes_its_selections(): void
    {
        $event = Event::factory()->create(['status' => 'draft']);
        $options = EventOption::factory()->for($event)->count(2)->sequence(
            ['position' => 1],
            ['position' => 2],
        )->create();
        $result = EventResult::factory()->for($event)->create([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $result->options()->attach($options[0]);
        $result->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->assertSame('published', $result->fresh()->status);
        $this->assertSame([$options[0]->id], $result->fresh()->options->pluck('id')->all());

        try {
            $result->update(['correction_reason' => 'Silent rewrite']);
            $this->fail('A published result should reject Eloquent updates.');
        } catch (LogicException) {
            $this->assertNull($result->fresh()->correction_reason);
            $result->refresh();
        }

        try {
            DB::table('event_results')->where('id', $result->id)->update(['version' => 99]);
            $this->fail('A published result should reject direct database updates.');
        } catch (QueryException) {
            $this->assertSame(1, $result->fresh()->version);
        }

        try {
            $result->options()->attach($options[1]);
            $this->fail('A published result should reject new selections.');
        } catch (QueryException) {
            $this->assertSame([$options[0]->id], $result->fresh()->options->pluck('id')->all());
        }

        try {
            $result->options()->detach($options[0]);
            $this->fail('A published result should reject selection removal.');
        } catch (QueryException) {
            $this->assertSame([$options[0]->id], $result->fresh()->options->pluck('id')->all());
        }

        try {
            $result->delete();
            $this->fail('Result history should reject Eloquent deletes.');
        } catch (LogicException) {
            $this->assertModelExists($result);
        }

        try {
            DB::table('event_results')->where('id', $result->id)->delete();
            $this->fail('Result history should reject direct database deletes.');
        } catch (QueryException) {
            $this->assertModelExists($result);
        }
    }

    public function test_official_result_retries_are_allowed_but_metadata_and_published_history_are_frozen(): void
    {
        $event = SeasonEvent::factory()->create();
        $options = SeasonEventOption::factory()->for($event, 'event')->count(2)->create();
        $result = SeasonEventResult::factory()->for($event, 'event')->create([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $result->options()->attach($options[0]);
        $result->update(['status' => 'pending']);
        $result->update(['status' => 'failed']);
        $result->update(['status' => 'pending']);

        try {
            $result->update(['correction_reason' => 'Changed while scoring']);
            $this->fail('Pending result metadata should be immutable.');
        } catch (LogicException) {
            $this->assertNull($result->fresh()->correction_reason);
            $result->refresh();
        }

        try {
            DB::table('season_event_results')->where('id', $result->id)->update(['version' => 99]);
            $this->fail('Pending result metadata should reject bulk updates.');
        } catch (QueryException) {
            $this->assertSame(1, $result->fresh()->version);
        }

        $result->update(['status' => 'published', 'published_at' => now()]);

        try {
            $result->options()->attach($options[1]);
            $this->fail('Published official selections should be immutable.');
        } catch (QueryException) {
            $this->assertSame([$options[0]->id], $result->fresh()->options->pluck('id')->all());
        }

        try {
            DB::table('season_event_results')->where('id', $result->id)->update(['status' => 'failed']);
            $this->fail('A published official result cannot return to a retry state.');
        } catch (QueryException) {
            $this->assertSame('published', $result->fresh()->status);
        }

        try {
            $result->delete();
            $this->fail('Official result history should reject Eloquent deletes.');
        } catch (LogicException) {
            $this->assertModelExists($result);
        }
    }

    public function test_point_entries_are_append_only_and_corrections_use_a_new_reversal_entry(): void
    {
        $entry = PointEntry::factory()->create(['points' => 4]);

        try {
            $entry->update(['points' => 40]);
            $this->fail('Point entries should reject Eloquent updates.');
        } catch (LogicException) {
            $this->assertSame(4, $entry->fresh()->points);
            $entry->refresh();
        }

        try {
            DB::table('point_entries')->where('id', $entry->id)->update(['points' => 40]);
            $this->fail('Point entries should reject bulk updates.');
        } catch (QueryException) {
            $this->assertSame(4, $entry->fresh()->points);
        }

        try {
            $entry->delete();
            $this->fail('Point entries should reject Eloquent deletes.');
        } catch (LogicException) {
            $this->assertModelExists($entry);
        }

        try {
            DB::table('point_entries')->where('id', $entry->id)->delete();
            $this->fail('Point entries should reject bulk deletes.');
        } catch (QueryException) {
            $this->assertModelExists($entry);
        }

        $reversal = PointEntry::query()->create([
            'pool_member_id' => $entry->pool_member_id,
            'event_id' => $entry->event_id,
            'event_result_id' => $entry->event_result_id,
            'reverses_point_entry_id' => $entry->id,
            'type' => 'reversal',
            'points' => -$entry->points,
            'reason' => 'Versioned correction',
            'idempotency_key' => 'test:append-only:reversal',
        ]);

        $this->assertModelExists($reversal);
        $this->assertSame(0, PointEntry::query()->where('pool_member_id', $entry->pool_member_id)->sum('points'));
    }
}
