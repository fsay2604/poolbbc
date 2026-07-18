<?php

namespace App\Actions\Drafts;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftStatus;
use App\Models\Draft;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeDraftStatus
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Draft $draft, User $administrator, DraftStatus $status): Draft
    {
        return DB::transaction(function () use ($draft, $administrator, $status): Draft {
            $draft = Draft::query()->lockForUpdate()->findOrFail($draft->id);

            if (! $draft->pool->isManagedBy($administrator)) {
                throw new AuthorizationException(__('You cannot manage this draft.'));
            }

            $allowed = match ($draft->status) {
                DraftStatus::Active => $status === DraftStatus::Paused,
                DraftStatus::Paused => $status === DraftStatus::Active,
                default => false,
            };

            if (! $allowed) {
                throw ValidationException::withMessages(['draft' => __('This draft status transition is not allowed.')]);
            }

            $previousStatus = $draft->status;
            $draft->update([
                'status' => $status,
                'turn_started_at' => $status === DraftStatus::Active ? now() : $draft->turn_started_at,
            ]);

            $this->recordAuditLog->handle($draft->pool, $administrator, 'draft.status_changed', $draft, [
                'status' => $status->value,
                'before' => ['status' => $previousStatus->value],
                'after' => ['status' => $draft->status->value],
            ]);

            return $draft->fresh(['currentMember.user']);
        });
    }
}
