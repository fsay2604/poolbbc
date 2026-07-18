<?php

namespace App\Actions\Events;

use App\Enums\AnswerSource;
use App\Models\SeasonEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SnapshotEligibleOptions
{
    /**
     * @param  list<int>  $excludedHouseguestIds
     * @param  list<string>  $customLabels
     */
    public function handle(SeasonEvent $event, array $excludedHouseguestIds = [], array $customLabels = [], ?bool $allowNone = null): SeasonEvent
    {
        return DB::transaction(function () use ($event, $excludedHouseguestIds, $customLabels, $allowNone): SeasonEvent {
            $event = SeasonEvent::query()->with('round.season')->lockForUpdate()->findOrFail($event->id);
            $allowNone ??= $event->allow_none;

            if ($event->options_locked_at !== null) {
                return $event->load('options');
            }

            if ($event->options()->exists()) {
                throw ValidationException::withMessages([
                    'options' => __('Existing official options must be cleared before the snapshot is frozen.'),
                ]);
            }

            $labels = match ($event->answer_source) {
                AnswerSource::Houseguests => [],
                AnswerSource::Boolean => [__('Yes'), __('No')],
                AnswerSource::Custom => array_values(array_unique(array_filter(array_map('trim', $customLabels)))),
            };

            if ($event->answer_source === AnswerSource::Houseguests) {
                $event->round->season->houseguests()
                    ->when(! $event->include_inactive_houseguests, fn ($query) => $query->where('is_active', true))
                    ->when($excludedHouseguestIds !== [], fn ($query) => $query->whereNotIn('id', $excludedHouseguestIds))
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->each(function ($houseguest, int $index) use ($event): void {
                        $event->options()->create([
                            'houseguest_id' => $houseguest->id,
                            'label' => $houseguest->name,
                            'value' => 'houseguest:'.$houseguest->id,
                            'position' => $index + 1,
                        ]);
                    });
            } else {
                if ($labels === []) {
                    throw ValidationException::withMessages(['options' => __('At least one official option is required.')]);
                }

                foreach ($labels as $index => $label) {
                    $event->options()->create([
                        'label' => $label,
                        'value' => Str::slug($label).':'.($index + 1),
                        'position' => $index + 1,
                    ]);
                }
            }

            if ($allowNone) {
                $event->options()->create([
                    'label' => __('No houseguest'),
                    'value' => 'none',
                    'position' => $event->options()->count() + 1,
                    'is_none' => true,
                ]);
            }

            $event->update(['options_locked_at' => now()]);

            return $event->fresh('options');
        }, attempts: 3);
    }
}
