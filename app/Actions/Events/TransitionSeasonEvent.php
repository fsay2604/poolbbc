<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use App\Models\SeasonEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionSeasonEvent
{
    public function __construct(
        private SnapshotEligibleOptions $snapshotEligibleOptions,
        private RecordAuditLog $recordAuditLog,
    ) {}

    public function synchronize(SeasonEvent $event): SeasonEvent
    {
        $effective = $event->effectiveStatus();

        if ($event->status === EventStatus::Draft && in_array($effective, [EventStatus::Open, EventStatus::Locked], true)) {
            $event = $this->transitionForSystem($event, EventStatus::Open);
        }

        if ($event->status === EventStatus::Open && $effective === EventStatus::Locked) {
            $event = $this->transitionForSystem($event, EventStatus::Locked);
        }

        return $event;
    }

    public function transition(SeasonEvent $event, EventStatus $target, User $actor, ?string $reason = null): SeasonEvent
    {
        return $this->transitionInternal($event, $target, $actor, $reason);
    }

    /** @internal Reserved for lifecycle synchronization and queue workers. */
    public function transitionForSystem(SeasonEvent $event, EventStatus $target): SeasonEvent
    {
        return $this->transitionInternal($event, $target, null);
    }

    private function transitionInternal(SeasonEvent $event, EventStatus $target, ?User $actor, ?string $reason = null): SeasonEvent
    {
        return DB::transaction(function () use ($event, $target, $actor, $reason): SeasonEvent {
            $event = SeasonEvent::query()->lockForUpdate()->findOrFail($event->id);
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('transition', $event);
            }
            $from = $event->status;

            if (! $this->isAllowed($from, $target)) {
                throw ValidationException::withMessages(['event' => __('This official event transition is not allowed.')]);
            }

            if ($target === EventStatus::Cancelled && blank($reason)) {
                throw ValidationException::withMessages(['cancellation_reason' => __('A cancellation reason is required.')]);
            }

            $attributes = ['status' => $target];
            if ($target === EventStatus::Open) {
                $attributes['opens_at'] = now();
            }
            if ($target === EventStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
            }

            if ($target === EventStatus::Open && $event->options_locked_at === null) {
                $event->update(['opens_at' => $attributes['opens_at']]);
                $event = $this->snapshotEligibleOptions->handle($event);
                $event->update(['status' => $target]);
            } else {
                if ($target === EventStatus::Open) {
                    unset($attributes['opens_at']);
                }

                $event->update($attributes);
            }

            if ($target === EventStatus::Locked) {
                $event->poolEvents()->each(function ($poolEvent): void {
                    $poolEvent->predictions()
                        ->where('status', PredictionStatus::Submitted->value)
                        ->update([
                            'status' => PredictionStatus::Locked->value,
                            'locked_at' => now(),
                            'updated_at' => now(),
                        ]);
                });
            }

            if ($actor !== null) {
                $this->recordAuditLog->handle(null, $actor, 'season_event.status_changed', $event, [
                    'from' => $from->value,
                    'to' => $target->value,
                    'reason' => $reason,
                ]);
            }

            return $event->fresh();
        }, attempts: 3);
    }

    private function isAllowed(EventStatus $from, EventStatus $to): bool
    {
        return match ($from) {
            EventStatus::Draft => in_array($to, [EventStatus::Open, EventStatus::Cancelled], true),
            EventStatus::Open => in_array($to, [EventStatus::Locked, EventStatus::Cancelled], true),
            EventStatus::Locked => in_array($to, [EventStatus::ResultEntered, EventStatus::Cancelled], true),
            EventStatus::ResultEntered => $to === EventStatus::Published,
            EventStatus::Published => $to === EventStatus::ResultEntered,
            EventStatus::Cancelled => false,
        };
    }
}
