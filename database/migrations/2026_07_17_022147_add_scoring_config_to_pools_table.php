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
        Schema::table('pools', function (Blueprint $table) {
            $table->json('scoring_config')->nullable()->after('competition_mode');
        });

        DB::table('pools')
            ->whereNull('scoring_config')
            ->update([
                'scoring_config' => json_encode([
                    'prediction' => [
                        'points_per_correct' => 2,
                        'exact_match_bonus' => 0,
                        'wrong_answer_penalty' => 0,
                    ],
                    'event_types' => [
                        'head-of-household' => ['owner_points' => 5],
                        'nomination' => ['owner_points' => 2],
                        'veto-winner' => ['owner_points' => 3],
                        'eviction' => ['owner_points' => -2],
                        'season-winner' => ['owner_points' => 10],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $canonicalAuthorityExists = Schema::hasTable('canonical_flow_authorities')
            && DB::table('canonical_flow_authorities')->where('marker', 'canonical')->exists();

        if (config('legacy-flow.canonical_is_authoritative') === true || $canonicalAuthorityExists) {
            throw new RuntimeException('Cannot remove pool scoring configuration after canonical authority has been established.');
        }

        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn('scoring_config');
        });
    }
};
