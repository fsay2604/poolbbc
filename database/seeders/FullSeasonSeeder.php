<?php

namespace Database\Seeders;

use App\Actions\Predictions\ScoreSeasonPredictions;
use App\Actions\Predictions\ScoreWeek;
use App\Actions\Seasons\CreateDefaultWeeks;
use App\Actions\Weeks\WeekPhaseManager;
use App\Enums\Occupation;
use App\Models\Houseguest;
use App\Models\Prediction;
use App\Models\Season;
use App\Models\SeasonPrediction;
use App\Models\User;
use App\Models\Week;
use App\Models\WeekOutcome;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class FullSeasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $year = now()->year;
        $season = Season::query()->firstOrCreate(
            ['name' => 'Full Season '.$year],
            [
                'is_active' => true,
                'starts_on' => now()->startOfYear()->toDateString(),
                'ends_on' => null,
            ],
        );

        Season::query()->where('id', '!=', $season->id)->update(['is_active' => false]);
        $season->forceFill(['is_active' => true])->save();

        app(CreateDefaultWeeks::class)->run($season);

        $this->seedHouseguests($season);
        $houseguests = Houseguest::query()
            ->where('season_id', $season->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $users = $this->seedUsers();
        $admin = $users->first();

        if ($admin !== null && $admin->is_admin !== true) {
            $admin->forceFill(['is_admin' => true])->save();
        }

        $this->seedSeasonOutcome($season, $houseguests);
        $this->seedSeasonPredictions($season, $houseguests, $users);

        if ($admin !== null) {
            app(ScoreSeasonPredictions::class)->run($season, $admin);
        }

        $phaseManager = app(WeekPhaseManager::class);

        $season->weeks()
            ->orderBy('number')
            ->get()
            ->each(function (Week $week) use ($houseguests, $users, $admin, $phaseManager): void {
                $week->forceFill([
                    'is_locked' => true,
                    'locked_at' => now(),
                ])->save();

                $phaseManager->ensureDefaultPhases($week);
                $week->load('phases');

                $outcome = $this->seedWeekOutcome($week, $houseguests, $admin);
                $this->seedWeekPredictions($week, $users, $houseguests);

                $week->setRelation('outcome', $outcome);
                if ($admin !== null) {
                    app(ScoreWeek::class)->run($week, $admin);
                }
            });
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    private function seedUsers(): Collection
    {
        $users = collect();

        for ($i = 1; $i <= 15; $i++) {
            $email = 'fullseason'.$i.'@example.com';
            $name = 'Season User '.$i;
            $isAdmin = $i === 1;

            $existing = User::query()->where('email', $email)->first();
            if ($existing !== null) {
                if ($isAdmin && $existing->is_admin !== true) {
                    $existing->forceFill(['is_admin' => true])->save();
                }

                $users->push($existing);

                continue;
            }

            $users->push(
                User::factory()
                    ->state([
                        'name' => $name,
                        'email' => $email,
                        'is_admin' => $isAdmin,
                    ])
                    ->create()
            );
        }

        return $users;
    }

    private function seedHouseguests(Season $season): void
    {
        $occupations = Occupation::values();

        $season->houseguests()
            ->get()
            ->each(function (Houseguest $houseguest) use ($occupations): void {
                if (is_array($houseguest->occupations) && $houseguest->occupations !== []) {
                    return;
                }

                $houseguest->forceFill([
                    'occupations' => $this->randomOccupations($occupations),
                ])->save();
            });

        $existingCount = $season->houseguests()->count();

        for ($i = $existingCount + 1; $i <= 16; $i++) {
            Houseguest::factory()->for($season)->create([
                'name' => 'HG '.$i,
                'is_active' => true,
                'sort_order' => $i,
                'occupations' => $this->randomOccupations($occupations),
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Houseguest>  $houseguests
     */
    private function seedSeasonOutcome(Season $season, Collection $houseguests): void
    {
        $ids = $houseguests->pluck('id')->values()->all();
        shuffle($ids);

        $season->forceFill([
            'winner_houseguest_id' => $ids[0] ?? null,
            'first_evicted_houseguest_id' => $ids[1] ?? null,
            'top_6_houseguest_ids' => array_slice($ids, 0, 6),
        ])->save();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Houseguest>  $houseguests
     * @param  \Illuminate\Support\Collection<int, \App\Models\User>  $users
     */
    private function seedSeasonPredictions(Season $season, Collection $houseguests, Collection $users): void
    {
        $ids = $houseguests->pluck('id')->values()->all();

        $users->each(function (User $user) use ($season, $ids): void {
            $selection = $ids;
            shuffle($selection);

            $prediction = SeasonPrediction::query()->updateOrCreate(
                ['season_id' => $season->id, 'user_id' => $user->id],
                [
                    'winner_houseguest_id' => $selection[0] ?? null,
                    'first_evicted_houseguest_id' => $selection[1] ?? null,
                    'top_6_houseguest_ids' => array_slice($selection, 0, 6),
                ],
            );

            $prediction->confirm();
            $prediction->save();
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Houseguest>  $houseguests
     */
    private function seedWeekOutcome(Week $week, Collection $houseguests, ?User $admin): WeekOutcome
    {
        $ids = $houseguests->pluck('id')->values()->all();
        shuffle($ids);

        $hohId = array_shift($ids);
        $nominee1Id = array_shift($ids);
        $nominee2Id = array_shift($ids);
        $vetoWinnerId = array_shift($ids);
        $vetoUsed = (bool) random_int(0, 1);

        $savedIds = [];
        $replacementIds = [];
        $evictedId = $nominee1Id;

        if ($vetoUsed) {
            $savedIds = [$nominee1Id];
            $replacementId = array_shift($ids);
            if ($replacementId !== null) {
                $replacementIds = [$replacementId];
                $evictedId = $replacementId;
            }
        }

        return WeekOutcome::query()->updateOrCreate(
            ['week_id' => $week->id],
            [
                'phase_results' => $this->defaultPayload(
                    $week,
                    [
                        'hoh_ids' => $hohId !== null ? [$hohId] : [],
                        'nominee_ids' => array_values(array_filter([$nominee1Id, $nominee2Id])),
                        'veto_used' => $vetoUsed,
                        'winner_ids' => $vetoWinnerId !== null ? [$vetoWinnerId] : [],
                        'saved_ids' => $savedIds,
                        'replacement_ids' => $replacementIds,
                        'evicted_ids' => $evictedId !== null ? [$evictedId] : [],
                    ],
                ),
                'last_admin_edited_by_user_id' => $admin?->id,
                'last_admin_edited_at' => now(),
            ],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\User>  $users
     * @param  \Illuminate\Support\Collection<int, \App\Models\Houseguest>  $houseguests
     */
    private function seedWeekPredictions(Week $week, Collection $users, Collection $houseguests): void
    {
        $ids = $houseguests->pluck('id')->values()->all();

        $users->each(function (User $user) use ($week, $ids): void {
            $selection = $ids;
            shuffle($selection);

            $hohId = array_shift($selection);
            $nominee1Id = array_shift($selection);
            $nominee2Id = array_shift($selection);
            $vetoWinnerId = array_shift($selection);
            $vetoUsed = (bool) random_int(0, 1);

            $savedIds = [];
            $replacementIds = [];
            $evictedId = $nominee1Id;

            if ($vetoUsed) {
                $savedIds = [$nominee1Id];
                $replacementId = array_shift($selection);
                if ($replacementId !== null) {
                    $replacementIds = [$replacementId];
                    $evictedId = $replacementId;
                }
            }

            $prediction = Prediction::query()->updateOrCreate(
                ['week_id' => $week->id, 'user_id' => $user->id],
                [
                    'phase_picks' => $this->defaultPayload(
                        $week,
                        [
                            'hoh_ids' => $hohId !== null ? [$hohId] : [],
                            'nominee_ids' => array_values(array_filter([$nominee1Id, $nominee2Id])),
                            'veto_used' => $vetoUsed,
                            'winner_ids' => $vetoWinnerId !== null ? [$vetoWinnerId] : [],
                            'saved_ids' => $savedIds,
                            'replacement_ids' => $replacementIds,
                            'evicted_ids' => $evictedId !== null ? [$evictedId] : [],
                        ],
                    ),
                ],
            );

            $prediction->confirm();
            $prediction->save();
        });
    }

    /**
     * @param  list<string>  $occupations
     * @return list<string>
     */
    private function randomOccupations(array $occupations): array
    {
        $count = random_int(1, 2);
        $indexes = array_rand($occupations, $count);
        $indexes = is_array($indexes) ? $indexes : [$indexes];

        return array_values(array_map(
            fn (int $index): string => $occupations[$index],
            $indexes,
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function defaultPayload(Week $week, array $values): array
    {
        $phases = $week->phases->keyBy('type');

        return [
            [
                'phase_id' => $phases[WeekPhaseManager::TYPE_HOH]->id,
                'position' => $phases[WeekPhaseManager::TYPE_HOH]->position,
                'type' => WeekPhaseManager::TYPE_HOH,
                'hoh_ids' => $values['hoh_ids'] ?? [],
            ],
            [
                'phase_id' => $phases[WeekPhaseManager::TYPE_NOMINEES]->id,
                'position' => $phases[WeekPhaseManager::TYPE_NOMINEES]->position,
                'type' => WeekPhaseManager::TYPE_NOMINEES,
                'nominee_ids' => $values['nominee_ids'] ?? [],
            ],
            [
                'phase_id' => $phases[WeekPhaseManager::TYPE_VETO]->id,
                'position' => $phases[WeekPhaseManager::TYPE_VETO]->position,
                'type' => WeekPhaseManager::TYPE_VETO,
                'veto_used' => (bool) ($values['veto_used'] ?? false),
                'winner_ids' => $values['winner_ids'] ?? [],
                'saved_ids' => $values['saved_ids'] ?? [],
                'replacement_ids' => $values['replacement_ids'] ?? [],
            ],
            [
                'phase_id' => $phases[WeekPhaseManager::TYPE_EVICTIONS]->id,
                'position' => $phases[WeekPhaseManager::TYPE_EVICTIONS]->position,
                'type' => WeekPhaseManager::TYPE_EVICTIONS,
                'evicted_ids' => $values['evicted_ids'] ?? [],
            ],
        ];
    }
}
