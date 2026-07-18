<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->timestamp('prediction_opens_at')->nullable()->after('ends_on');
            $table->timestamp('prediction_locks_at')->nullable()->after('prediction_opens_at')->index();
        });

        DB::table('seasons')->orderBy('id')->get()->each(function (object $season): void {
            $firstWeekLock = DB::table('weeks')
                ->where('season_id', $season->id)
                ->whereNotNull('auto_lock_at')
                ->orderBy('number')
                ->value('auto_lock_at');
            $opensAt = filled($season->starts_on)
                ? Carbon::parse($season->starts_on)->startOfDay()
                : Carbon::parse($season->created_at);
            $locksAt = filled($firstWeekLock)
                ? Carbon::parse($firstWeekLock)
                : $opensAt->copy()->addDay();

            DB::table('seasons')->where('id', $season->id)->update([
                'prediction_opens_at' => $opensAt,
                'prediction_locks_at' => $locksAt,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn(['prediction_opens_at', 'prediction_locks_at']);
        });
    }
};
