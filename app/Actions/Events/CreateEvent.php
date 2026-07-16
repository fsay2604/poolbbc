<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AnswerSource;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEvent
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param array<string, mixed> $data */
    public function handle(Pool $pool, User $creator, array $data): Event
    {
        return DB::transaction(function () use ($pool, $creator, $data): Event {
            if (filled($data['opens_at'] ?? null)
                && Carbon::parse($data['locks_at'])->lessThanOrEqualTo(Carbon::parse($data['opens_at']))) {
                throw ValidationException::withMessages(['eventForm.locks_at' => __('The lock time must be after the opening time.')]);
            }

            $round = $pool->rounds()->findOrFail($data['round_id']);
            $position = ((int) $round->events()->max('position')) + 1;

            $event = $pool->events()->create([
                ...$data,
                'round_id' => $round->id,
                'created_by' => $creator->id,
                'position' => $position,
                'status' => EventStatus::Draft,
                'scoring_config' => $data['scoring_config'] ?? [
                    'owner' => ['points_per_match' => 5],
                    'prediction' => ['points_per_correct' => 2, 'exact_match_bonus' => 0, 'wrong_answer_penalty' => 0],
                    'allow_negative' => false,
                ],
            ]);

            $this->createOptions($event, $pool, $data);
            $this->recordAuditLog->handle($pool, $creator, 'event.created', $event);

            return $event->load('options', 'round', 'eventType');
        });
    }

    /** @param array<string, mixed> $data */
    private function createOptions(Event $event, Pool $pool, array $data): void
    {
        $source = $event->answer_source;

        if ($source === AnswerSource::Houseguests) {
            $pool->season->houseguests()->where('is_active', true)->orderBy('sort_order')->get()
                ->each(function ($houseguest, int $index) use ($event): void {
                    $event->options()->create([
                        'houseguest_id' => $houseguest->id,
                        'label' => $houseguest->name,
                        'value' => 'houseguest:'.$houseguest->id,
                        'position' => $index + 1,
                    ]);
                });

            if (($data['allow_none'] ?? false) === true) {
                $event->options()->create([
                    'label' => __('No houseguest'),
                    'value' => 'none',
                    'position' => $event->options()->count() + 1,
                    'is_none' => true,
                ]);
            }

            return;
        }

        $labels = $source === AnswerSource::Boolean
            ? [__('Yes'), __('No')]
            : ($data['custom_options'] ?? []);

        if ($labels === []) {
            throw ValidationException::withMessages(['form.custom_options' => __('At least one option is required.')]);
        }

        foreach ($labels as $index => $label) {
            $event->options()->create([
                'label' => $label,
                'value' => Str::slug($label).':'.($index + 1),
                'position' => $index + 1,
            ]);
        }
    }
}
