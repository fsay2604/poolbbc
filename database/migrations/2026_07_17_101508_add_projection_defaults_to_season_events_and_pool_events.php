<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_types')
            ->whereNull('pool_id')
            ->where('scope_key', 'global')
            ->where('is_standard', true)
            ->where('default_mode', 'roster')
            ->whereIn('slug', [
                'head-of-household',
                'nomination',
                'veto-winner',
                'eviction',
                'season-winner',
            ])
            ->update(['default_mode' => 'hybrid']);

        if (! Schema::hasColumn('season_events', 'default_mode')) {
            Schema::table('season_events', function (Blueprint $table): void {
                $table->string('default_mode')->default('hybrid')->after('scoring_config');
            });
        }

        if (! Schema::hasColumn('pool_events', 'rules_customized_at')) {
            Schema::table('pool_events', function (Blueprint $table): void {
                $table->timestamp('rules_customized_at')->nullable()->after('scoring_config');
            });
        }

        $eventTypeModes = DB::table('event_types')->pluck('default_mode', 'id');

        DB::table('season_events')
            ->select(['id', 'event_type_id'])
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($eventTypeModes): void {
                foreach ($events as $event) {
                    $mode = $event->event_type_id === null
                        ? null
                        : $eventTypeModes->get($event->event_type_id);

                    if (! in_array($mode, ['roster', 'prediction', 'hybrid'], true)) {
                        $mode = DB::table('pool_events')
                            ->where('season_event_id', $event->id)
                            ->orderBy('id')
                            ->value('mode');
                    }

                    DB::table('season_events')->where('id', $event->id)->update([
                        'default_mode' => in_array($mode, ['roster', 'prediction', 'hybrid'], true)
                            ? $mode
                            : 'prediction',
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        $canonicalAuthorityExists = Schema::hasTable('canonical_flow_authorities')
            && DB::table('canonical_flow_authorities')->where('marker', 'canonical')->exists();

        if (config('legacy-flow.canonical_is_authoritative') === true || $canonicalAuthorityExists) {
            throw new RuntimeException('Cannot remove canonical event projection defaults after canonical authority has been established.');
        }

        if (Schema::hasColumn('pool_events', 'rules_customized_at')) {
            Schema::table('pool_events', function (Blueprint $table): void {
                $table->dropColumn('rules_customized_at');
            });
        }

        if (Schema::hasColumn('season_events', 'default_mode')) {
            Schema::table('season_events', function (Blueprint $table): void {
                $table->dropColumn('default_mode');
            });
        }

        DB::table('event_types')
            ->whereNull('pool_id')
            ->where('scope_key', 'global')
            ->where('is_standard', true)
            ->where('default_mode', 'hybrid')
            ->whereIn('slug', [
                'head-of-household',
                'nomination',
                'veto-winner',
                'eviction',
                'season-winner',
            ])
            ->update(['default_mode' => 'roster']);
    }
};
