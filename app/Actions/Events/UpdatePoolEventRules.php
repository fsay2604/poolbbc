<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Models\PoolEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdatePoolEventRules
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param array<string, mixed> $rules */
    public function handle(PoolEvent $poolEvent, User $manager, array $rules): PoolEvent
    {
        return DB::transaction(function () use ($poolEvent, $manager, $rules): PoolEvent {
            $poolEvent = PoolEvent::query()
                ->with(['pool', 'seasonEvent.options'])
                ->lockForUpdate()
                ->findOrFail($poolEvent->id);

            Gate::forUser($manager)->authorize('update', $poolEvent->pool);

            if ($poolEvent->seasonEvent === null
                || ! in_array($poolEvent->seasonEvent->effectiveStatus(), [EventStatus::Draft, EventStatus::Open], true)
                || $poolEvent->predictions()->exists()
                || $poolEvent->pointEntries()->exists()) {
                throw ValidationException::withMessages([
                    'rules' => __('Pool scoring rules cannot change after the first response or event lock.'),
                ]);
            }

            $mode = EventMode::tryFrom((string) ($rules['mode'] ?? ''));
            $minimum = (int) ($rules['prediction_min_selections'] ?? -1);
            $maximum = (int) ($rules['prediction_max_selections'] ?? -1);
            $visibility = (string) ($rules['visibility'] ?? $poolEvent->visibility);
            $availableOptionCount = $poolEvent->seasonEvent->options->count();

            if ($mode === null || ! $poolEvent->pool->supportsEventMode($mode)) {
                throw ValidationException::withMessages([
                    'rules.mode' => __('This event mode is incompatible with the pool competition mode.'),
                ]);
            }

            if ($minimum < 0 || $maximum < 1 || $maximum < $minimum
                || ($availableOptionCount > 0 && $maximum > $availableOptionCount)) {
                throw ValidationException::withMessages([
                    'rules.prediction_max_selections' => __('The prediction selection limits are invalid.'),
                ]);
            }

            if (! in_array($visibility, ['after_lock', 'after_publish'], true)) {
                throw ValidationException::withMessages([
                    'rules.visibility' => __('The prediction visibility setting is invalid.'),
                ]);
            }

            $before = $poolEvent->only([
                'mode', 'visibility', 'prediction_min_selections', 'prediction_max_selections', 'scoring_config',
            ]);

            $poolEvent->update([
                'mode' => $mode,
                'visibility' => $visibility,
                'prediction_min_selections' => $minimum,
                'prediction_max_selections' => $maximum,
                'scoring_config' => [
                    'owner' => [
                        'points_per_match' => (int) $rules['owner_points'],
                    ],
                    'prediction' => [
                        'points_per_correct' => (int) $rules['prediction_points'],
                        'exact_match_bonus' => (int) $rules['exact_bonus'],
                        'wrong_answer_penalty' => (int) $rules['wrong_penalty'],
                    ],
                    'allow_negative' => (bool) $rules['allow_negative'],
                ],
                'rules_customized_at' => now(),
            ]);

            $this->recordAuditLog->handle($poolEvent->pool, $manager, 'pool_event.rules_updated', $poolEvent, [
                'before' => $before,
                'after' => $poolEvent->only([
                    'mode', 'visibility', 'prediction_min_selections', 'prediction_max_selections', 'scoring_config',
                ]),
            ]);

            return $poolEvent->fresh(['seasonEvent', 'pool']);
        }, attempts: 3);
    }
}
