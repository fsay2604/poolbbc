<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('event_types', 'scope_key')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->string('scope_key')->default('global')->after('pool_id');
            });
        }

        DB::table('event_types')
            ->whereNotNull('pool_id')
            ->get(['id', 'pool_id'])
            ->each(fn (object $eventType) => DB::table('event_types')
                ->where('id', $eventType->id)
                ->update(['scope_key' => 'pool:'.$eventType->pool_id]));

        DB::table('event_types')
            ->whereNull('pool_id')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('slug')
            ->each(function (string $slug): void {
                $ids = DB::table('event_types')
                    ->whereNull('pool_id')
                    ->where('slug', $slug)
                    ->orderBy('id')
                    ->pluck('id');
                $canonicalId = $ids->shift();

                DB::table('events')->whereIn('event_type_id', $ids)->update(['event_type_id' => $canonicalId]);
                DB::table('season_events')->whereIn('event_type_id', $ids)->update(['event_type_id' => $canonicalId]);
                DB::table('event_types')->whereIn('id', $ids)->delete();
            });

        if (! Schema::hasIndex('event_types', 'event_types_pool_id_index')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->index('pool_id', 'event_types_pool_id_index');
            });
        }

        if (Schema::hasIndex('event_types', 'event_types_pool_id_slug_unique')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->dropUnique('event_types_pool_id_slug_unique');
            });
        }

        if (! Schema::hasIndex('event_types', 'event_types_scope_slug_unique')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->unique(['scope_key', 'slug'], 'event_types_scope_slug_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('event_types', 'event_types_scope_slug_unique')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->dropUnique('event_types_scope_slug_unique');
            });
        }

        if (! Schema::hasIndex('event_types', 'event_types_pool_id_slug_unique')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->unique(['pool_id', 'slug']);
            });
        }

        if (Schema::hasIndex('event_types', 'event_types_pool_id_index')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->dropIndex('event_types_pool_id_index');
            });
        }

        if (Schema::hasColumn('event_types', 'scope_key')) {
            Schema::table('event_types', function (Blueprint $table) {
                $table->dropColumn('scope_key');
            });
        }
    }
};
