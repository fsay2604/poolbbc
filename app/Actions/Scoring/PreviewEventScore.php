<?php

namespace App\Actions\Scoring;

use App\Enums\EventMode;
use App\Enums\PredictionStatus;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PreviewEventScore
{
    /** @param list<int> $optionIds
     * @return Collection<int, array{name:string,points:int}>
     */
    public function handle(Event $event, array $optionIds): Collection
    {
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        $resultOptions = $event->options()->whereKey($optionIds)->get();
        if ($resultOptions->count() !== count($optionIds)
            || count($optionIds) < $event->result_min_selections
            || count($optionIds) > $event->result_max_selections) {
            throw ValidationException::withMessages(['result' => __('The official result selection is invalid.')]);
        }

        if (count($optionIds) > 1 && $resultOptions->contains('is_none', true)) {
            throw ValidationException::withMessages([
                'result' => __('The explicit none option cannot be combined with another answer.'),
            ]);
        }

        $event->load(['predictions' => fn ($query) => $query
            ->whereIn('status', [PredictionStatus::Submitted->value, PredictionStatus::Locked->value])
            ->with('options')]);
        $resultOptionIds = $resultOptions->pluck('id')->sort()->values();
        $resultHouseguestIds = $resultOptions->pluck('houseguest_id')->filter();

        return $event->pool->competitionMembers()
            ->with(['user', 'draftPicks'])
            ->get()
            ->map(function ($member) use ($event, $resultOptionIds, $resultHouseguestIds): array {
                $points = 0;

                if (in_array($event->mode, [EventMode::Roster, EventMode::Hybrid], true)) {
                    $matches = $member->draftPicks->whereIn('houseguest_id', $resultHouseguestIds)->count();
                    $points += $matches * (int) data_get($event->scoring_config, 'owner.points_per_match', 0);
                }

                if (in_array($event->mode, [EventMode::Prediction, EventMode::Hybrid], true)) {
                    $prediction = $event->predictions->firstWhere('pool_member_id', $member->id);
                    $selectedIds = $prediction?->options->pluck('id')->sort()->values() ?? collect();
                    $correct = $selectedIds->intersect($resultOptionIds)->count();
                    $wrong = $selectedIds->diff($resultOptionIds)->count();
                    $isExact = $prediction !== null && $selectedIds->all() === $resultOptionIds->all();
                    $points += $correct * (int) data_get($event->scoring_config, 'prediction.points_per_correct', 0);
                    $points += $wrong * (int) data_get($event->scoring_config, 'prediction.wrong_answer_penalty', 0);
                    $points += $isExact ? (int) data_get($event->scoring_config, 'prediction.exact_match_bonus', 0) : 0;
                }

                if (! (bool) data_get($event->scoring_config, 'allow_negative', false)) {
                    $points = max(0, $points);
                }

                return ['name' => $member->user->name, 'points' => $points];
            })
            ->sortByDesc('points')
            ->values();
    }
}
