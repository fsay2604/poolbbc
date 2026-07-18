<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventMode;
use App\Http\Requests\Admin\UpdateStandardEventTypeRequest;
use App\Models\EventType;
use App\Models\User;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateStandardEventType
{
    public function __construct(
        private StandardEventTypeCatalog $catalog,
        private RecordAuditLog $recordAuditLog,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(EventType $eventType, User $administrator, array $data): EventType
    {
        Gate::forUser($administrator)->authorize('update', $eventType);
        $request = new UpdateStandardEventTypeRequest;
        $validated = Validator::make(
            ['form' => $data],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        )->validate()['form'];

        return DB::transaction(function () use ($eventType, $administrator, $validated): EventType {
            $eventType = EventType::query()->lockForUpdate()->findOrFail($eventType->id);
            Gate::forUser($administrator)->authorize('update', $eventType);

            if (! $this->catalog->has($eventType->slug)) {
                throw ValidationException::withMessages([
                    'form' => __('This standard event type is not managed by the application catalog.'),
                ]);
            }

            $before = $this->snapshot($eventType);
            $eventType->update([
                'name' => $validated['name'],
                'default_mode' => EventMode::from($validated['default_mode']),
                'default_config' => [
                    'question' => filled($validated['question'] ?? null) ? trim($validated['question']) : null,
                    'prediction_min_selections' => (int) $validated['prediction_min_selections'],
                    'prediction_max_selections' => (int) $validated['prediction_max_selections'],
                    'result_min_selections' => (int) $validated['result_min_selections'],
                    'result_max_selections' => (int) $validated['result_max_selections'],
                    'include_inactive_houseguests' => (bool) $validated['include_inactive_houseguests'],
                    'allow_none' => (bool) $validated['allow_none'],
                    'result_publication_mode' => $validated['result_publication_mode'],
                    'owner' => ['points_per_match' => (int) $validated['owner_points']],
                    'prediction' => [
                        'points_per_correct' => (int) $validated['prediction_points'],
                        'exact_match_bonus' => (int) $validated['exact_bonus'],
                        'wrong_answer_penalty' => (int) $validated['wrong_penalty'],
                    ],
                    'allow_negative' => (bool) $validated['allow_negative'],
                ],
            ]);

            $eventType = $eventType->fresh();
            $this->recordAuditLog->handle(null, $administrator, 'event_type.updated', $eventType, [
                'before' => $before,
                'after' => $this->snapshot($eventType),
            ]);

            return $eventType;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(EventType $eventType): array
    {
        return [
            'name' => $eventType->name,
            'slug' => $eventType->slug,
            'default_mode' => $eventType->default_mode->value,
            'answer_source' => $eventType->answer_source->value,
            'default_config' => $this->catalog->normalizeDefaultConfig($eventType->slug, $eventType->default_config),
        ];
    }
}
