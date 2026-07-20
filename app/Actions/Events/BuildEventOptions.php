<?php

namespace App\Actions\Events;

use App\Enums\AnswerSource;
use App\Models\Event;
use App\Models\Pool;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuildEventOptions
{
    /** @param array<string, mixed> $data */
    public function handle(Event $event, Pool $pool, array $data): void
    {
        $options = match ($event->answer_source) {
            AnswerSource::Houseguests => $this->houseguestOptions($pool, $data),
            AnswerSource::Boolean => $this->labelOptions([__('Yes'), __('No')]),
            AnswerSource::Custom => $this->labelOptions($this->customLabels($data)),
        };

        if ($event->answer_source === AnswerSource::Houseguests
            && ($data['allow_none'] ?? false) === true) {
            $options[] = [
                'label' => __('No houseguest'),
                'value' => 'none',
                'position' => count($options) + 1,
                'is_none' => true,
            ];
        }

        $event->options()->createMany($options);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function houseguestOptions(Pool $pool, array $data): array
    {
        return $pool->season->houseguests()
            ->when(! ($data['include_inactive_houseguests'] ?? false), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->get(['houseguests.id', 'houseguests.name'])
            ->values()
            ->map(fn ($houseguest, int $index): array => [
                'houseguest_id' => $houseguest->id,
                'label' => $houseguest->name,
                'value' => 'houseguest:'.$houseguest->id,
                'position' => $index + 1,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $labels
     * @return list<array<string, mixed>>
     */
    private function labelOptions(array $labels): array
    {
        return collect($labels)
            ->values()
            ->map(fn (string $label, int $index): array => [
                'label' => $label,
                'value' => Str::slug($label).':'.($index + 1),
                'position' => $index + 1,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function customLabels(array $data): array
    {
        $labels = collect($data['custom_options'] ?? [])
            ->filter(fn (mixed $label): bool => is_string($label))
            ->map(fn (string $label): string => trim($label))
            ->filter()
            ->unique(fn (string $label): string => Str::lower($label))
            ->values()
            ->all();

        if ($labels === []) {
            throw ValidationException::withMessages([
                'eventForm.custom_options' => __('At least one option is required.'),
            ]);
        }

        return $labels;
    }
}
