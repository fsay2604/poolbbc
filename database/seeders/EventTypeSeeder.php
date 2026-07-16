<?php

namespace Database\Seeders;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Models\EventType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['slug' => 'head-of-household', 'name' => 'Patron de la semaine', 'owner_points' => 5],
            ['slug' => 'nomination', 'name' => 'Mise en danger', 'owner_points' => 2],
            ['slug' => 'veto-winner', 'name' => 'Gagnant du veto', 'owner_points' => 3],
            ['slug' => 'eviction', 'name' => 'Élimination', 'owner_points' => -2],
            ['slug' => 'season-winner', 'name' => 'Gagnant de la saison', 'owner_points' => 10],
        ];

        foreach ($types as $type) {
            EventType::query()->updateOrCreate(
                ['pool_id' => null, 'slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'is_standard' => true,
                    'default_mode' => EventMode::Roster,
                    'answer_source' => AnswerSource::Houseguests,
                    'default_config' => [
                        'owner' => ['points_per_match' => $type['owner_points']],
                        'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                        'allow_negative' => $type['owner_points'] < 0,
                    ],
                ],
            );
        }
    }
}
