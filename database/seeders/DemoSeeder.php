<?php

namespace Database\Seeders;

use App\Actions\Predictions\ScoreWeek;
use App\Actions\Seasons\CreateDefaultWeeks;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\User;
use App\Models\WeekOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $year = now()->year;

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'email_verified_at' => now(),
                'is_admin' => true,
            ],
        );
        $admin->forceFill(['is_admin' => true])->save();

        $user1 = User::query()->firstOrCreate(
            ['email' => 'demo1@example.com'],
            [
                'name' => 'Demo User 1',
                'password' => 'password',
                'email_verified_at' => now(),
                'is_admin' => false,
            ],
        );

        $user2 = User::query()->firstOrCreate(
            ['email' => 'demo2@example.com'],
            [
                'name' => 'Demo User 2',
                'password' => 'password',
                'email_verified_at' => now(),
                'is_admin' => false,
            ],
        );

        $season = Season::query()->firstOrCreate(
            ['name' => 'Demo Season '.$year],
            [
                'is_active' => true,
                'starts_on' => Carbon::create($year, 1, 1),
                'ends_on' => null,
            ],
        );

        Season::query()->where('id', '!=', $season->id)->update(['is_active' => false]);
        $season->forceFill(['is_active' => true])->save();

        app(CreateDefaultWeeks::class)->run($season);

        if ($season->houseguests()->count() < 16) {
            $existing = $season->houseguests()->count();

            for ($i = $existing + 1; $i <= 16; $i++) {
                Houseguest::factory()->for($season)->create([
                    'name' => 'HG '.$i,
                    'is_active' => true,
                    'sort_order' => $i,
                ]);
            }
        }

        /** @var Collection<int, Houseguest> $houseguests */
        $houseguests = Houseguest::query()
            ->where('season_id', $season->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        abort_if($houseguests->count() < 10, 422, 'Need at least 10 active houseguests to seed demo predictions.');

        $week1 = $season->weeks()->where('number', 1)->firstOrFail();
        $week2 = $season->weeks()->where('number', 2)->firstOrFail();

        // Week 1 outcome (veto used)
        $w1Boss = $houseguests[0];
        $w1Nom1 = $houseguests[1];
        $w1Nom2 = $houseguests[2];
        $w1VetoWinner = $houseguests[3];
        $w1Saved = $w1Nom1;
        $w1Replacement = $houseguests[4];
        $w1Evicted = $w1Replacement;

        WeekOutcome::query()->updateOrCreate(
            ['week_id' => $week1->id],
            [
                'hoh_houseguest_id' => $w1Boss->id,
                'nominee_1_houseguest_id' => $w1Nom1->id,
                'nominee_2_houseguest_id' => $w1Nom2->id,
                'veto_winner_houseguest_id' => $w1VetoWinner->id,
                'veto_used' => true,
                'saved_houseguest_id' => $w1Saved->id,
                'replacement_nominee_houseguest_id' => $w1Replacement->id,
                'evicted_houseguest_id' => $w1Evicted->id,
                'last_admin_edited_by_user_id' => $admin->id,
                'last_admin_edited_at' => now(),
            ],
        );

        // Week 2 outcome (veto NOT used)
        $w2Boss = $houseguests[5];
        $w2Nom1 = $houseguests[6];
        $w2Nom2 = $houseguests[7];
        $w2VetoWinner = $houseguests[8];
        $w2Evicted = $w2Nom1;

        WeekOutcome::query()->updateOrCreate(
            ['week_id' => $week2->id],
            [
                'hoh_houseguest_id' => $w2Boss->id,
                'nominee_1_houseguest_id' => $w2Nom1->id,
                'nominee_2_houseguest_id' => $w2Nom2->id,
                'veto_winner_houseguest_id' => $w2VetoWinner->id,
                'veto_used' => false,
                'saved_houseguest_id' => null,
                'replacement_nominee_houseguest_id' => null,
                'evicted_houseguest_id' => $w2Evicted->id,
                'last_admin_edited_by_user_id' => $admin->id,
                'last_admin_edited_at' => now(),
            ],
        );

        // User predictions (confirmed)
        $prediction1w1 = Prediction::query()->updateOrCreate(
            ['week_id' => $week1->id, 'user_id' => $user1->id],
            [
                'hoh_houseguest_id' => $w1Boss->id,
                'nominee_1_houseguest_id' => $w1Nom1->id,
                'nominee_2_houseguest_id' => $w1Nom2->id,
                'veto_winner_houseguest_id' => $w1VetoWinner->id,
                'veto_used' => true,
                'saved_houseguest_id' => $w1Saved->id,
                'replacement_nominee_houseguest_id' => $w1Replacement->id,
                'evicted_houseguest_id' => $w1Evicted->id,
            ],
        );
        $prediction1w1->confirm();
        $prediction1w1->save();

        $prediction2w1 = Prediction::query()->updateOrCreate(
            ['week_id' => $week1->id, 'user_id' => $user2->id],
            [
                'hoh_houseguest_id' => $w1Boss->id,
                'nominee_1_houseguest_id' => $w1Nom1->id,
                'nominee_2_houseguest_id' => $houseguests[9]->id,
                'veto_winner_houseguest_id' => $houseguests[10]->id,
                'veto_used' => false,
                'saved_houseguest_id' => null,
                'replacement_nominee_houseguest_id' => null,
                'evicted_houseguest_id' => $w1Nom2->id,
            ],
        );
        $prediction2w1->confirm();
        $prediction2w1->save();

        $prediction1w2 = Prediction::query()->updateOrCreate(
            ['week_id' => $week2->id, 'user_id' => $user1->id],
            [
                'hoh_houseguest_id' => $w2Boss->id,
                'nominee_1_houseguest_id' => $w2Nom1->id,
                'nominee_2_houseguest_id' => $w2Nom2->id,
                'veto_winner_houseguest_id' => $houseguests[11]->id,
                'veto_used' => false,
                'saved_houseguest_id' => null,
                'replacement_nominee_houseguest_id' => null,
                'evicted_houseguest_id' => $w2Evicted->id,
            ],
        );
        $prediction1w2->confirm();
        $prediction1w2->save();

        $prediction2w2 = Prediction::query()->updateOrCreate(
            ['week_id' => $week2->id, 'user_id' => $user2->id],
            [
                'hoh_houseguest_id' => $houseguests[12]->id,
                'nominee_1_houseguest_id' => $w2Nom1->id,
                'nominee_2_houseguest_id' => $houseguests[13]->id,
                'veto_winner_houseguest_id' => $w2VetoWinner->id,
                'veto_used' => false,
                'saved_houseguest_id' => null,
                'replacement_nominee_houseguest_id' => null,
                'evicted_houseguest_id' => $w2Nom2->id,
            ],
        );
        $prediction2w2->confirm();
        $prediction2w2->save();

        // Calculate scores for week 1 & 2
        $week1->loadMissing('outcome');
        $week2->loadMissing('outcome');

        app(ScoreWeek::class)->run($week1, $admin);
        app(ScoreWeek::class)->run($week2, $admin);
    }
}
