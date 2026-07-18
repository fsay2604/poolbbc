<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AnswerSource;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\PoolStatus;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
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
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            if (! $pool->isManagedBy($creator)) {
                throw new AuthorizationException(__('You cannot create events for this pool.'));
            }

            if (in_array($pool->status, [PoolStatus::Completed, PoolStatus::Archived], true)) {
                throw ValidationException::withMessages([
                    'eventForm' => __('Events cannot be created after the pool is completed.'),
                ]);
            }

            $eventMode = EventMode::from($data['mode']);
            if (! $pool->supportsEventMode($eventMode)) {
                throw ValidationException::withMessages(['eventForm.mode' => __('This scoring mode is not available for the pool competition mode.')]);
            }

            if (filled($data['opens_at'] ?? null)
                && Carbon::parse($data['locks_at'])->lessThanOrEqualTo(Carbon::parse($data['opens_at']))) {
                throw ValidationException::withMessages(['eventForm.locks_at' => __('The lock time must be after the opening time.')]);
            }

            $round = $pool->rounds()->findOrFail($data['round_id']);
            $position = ((int) $round->events()->max('position')) + 1;

            if (filled($data['event_type_id'] ?? null)) {
                $eventType = EventType::query()
                    ->whereKey($data['event_type_id'])
                    ->where(fn ($query) => $query->whereNull('pool_id')->orWhere('pool_id', $pool->id))
                    ->firstOrFail();
                $data['event_type_id'] = $eventType->id;
            } elseif (($data['save_as_template'] ?? false) === true) {
                $eventType = $this->createReusableTemplate($pool, $creator, $data, $eventMode);
                $data['event_type_id'] = $eventType->id;
            }

            $event = $pool->events()->create([
                ...Arr::except($data, ['allow_none', 'custom_options', 'save_as_template']),
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
    private function createReusableTemplate(Pool $pool, User $creator, array $data, EventMode $eventMode): EventType
    {
        $eventType = EventType::query()->create([
            'pool_id' => $pool->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'is_standard' => false,
            'default_mode' => $eventMode,
            'answer_source' => AnswerSource::from($data['answer_source']),
            'default_config' => [
                ...($data['scoring_config'] ?? []),
                'question' => $data['question'] ?? null,
                'prediction_min_selections' => (int) $data['prediction_min_selections'],
                'prediction_max_selections' => (int) $data['prediction_max_selections'],
                'result_min_selections' => (int) $data['result_min_selections'],
                'result_max_selections' => (int) $data['result_max_selections'],
                'result_publication_mode' => (string) ($data['result_publication_mode'] ?? 'immediate'),
                'allow_none' => (bool) ($data['allow_none'] ?? false),
                'include_inactive_houseguests' => (bool) ($data['include_inactive_houseguests'] ?? false),
                'custom_options' => array_values($data['custom_options'] ?? []),
            ],
        ]);

        $this->recordAuditLog->handle($pool, $creator, 'event_template.created', $eventType);

        return $eventType;
    }

    /** @param array<string, mixed> $data */
    private function createOptions(Event $event, Pool $pool, array $data): void
    {
        $source = $event->answer_source;

        if ($source === AnswerSource::Houseguests) {
            $pool->season->houseguests()
                ->when(! ($data['include_inactive_houseguests'] ?? false), fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->get()
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
