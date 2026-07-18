<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Enums\PredictionStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SynchronizeEventLifecycle
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function synchronize(Event $event, ?User $actor = null): Event
    {
        $effectiveStatus = $event->effectiveStatus();

        if ($event->status === EventStatus::Draft && in_array($effectiveStatus, [EventStatus::Open, EventStatus::Locked], true)) {
            $event = $this->transition($event, EventStatus::Open, $actor);
        }

        if ($event->status === EventStatus::Open && $effectiveStatus === EventStatus::Locked) {
            $event = $this->transition($event, EventStatus::Locked, $actor);
        }

        return $event;
    }

    public function transition(Event $event, EventStatus $target, ?User $actor = null, ?string $reason = null): Event
    {
        return DB::transaction(function () use ($event, $target, $actor, $reason): Event {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $from = $event->status;

            if (! $this->isAllowed($event->status, $target)) {
                throw ValidationException::withMessages(['event' => __('This event status transition is not allowed.')]);
            }

            if ($target === EventStatus::Cancelled && blank($reason)) {
                throw ValidationException::withMessages(['cancellation_reason' => __('A cancellation reason is required.')]);
            }

            $attributes = ['status' => $target];
            if ($target === EventStatus::Open) {
                $attributes['opens_at'] = now();
            }
            if ($target === EventStatus::Published) {
                $attributes['published_at'] = now();
            }
            if ($target === EventStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
            }

            $event->update($attributes);

            if ($target === EventStatus::Locked) {
                $event->predictions()
                    ->where('status', PredictionStatus::Submitted->value)
                    ->update([
                        'status' => PredictionStatus::Locked->value,
                        'locked_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if ($actor !== null) {
                $this->recordAuditLog->handle($event->pool, $actor, 'event.status_changed', $event, [
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
