<?php

namespace App\Actions\Drafts;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftStatus;
use App\Models\DraftPick;
use App\Models\Houseguest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectDraftPick
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(DraftPick $pick, Houseguest $replacement, User $actor, string $reason): DraftPick
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['correction_reason' => __('A correction reason is required.')]);
        }

        return DB::transaction(function () use ($pick, $replacement, $actor, $reason): DraftPick {
            $pick = DraftPick::query()->with('pool')->lockForUpdate()->findOrFail($pick->id);
            $pool = $pick->pool;

            if (! $pool->isManagedBy($actor)) {
                throw new AuthorizationException(__('You cannot correct this draft.'));
            }

            $draft = $pool->draft()->lockForUpdate()->firstOrFail();
            if ($draft->status === DraftStatus::Completed) {
                throw ValidationException::withMessages([
                    'draft' => __('A completed draft is immutable.'),
                ]);
            }

            $replacement = Houseguest::query()->lockForUpdate()->findOrFail($replacement->id);

            if ($replacement->season_id !== $pool->season_id || ! $replacement->is_active) {
                throw ValidationException::withMessages(['houseguest' => __('This houseguest is not available.')]);
            }

            if ($replacement->id === $pick->houseguest_id) {
                throw ValidationException::withMessages(['houseguest' => __('Select a different houseguest for this correction.')]);
            }

            if ($pool->exclusive_draft && DraftPick::query()
                ->where('pool_id', $pool->id)
                ->where('id', '!=', $pick->id)
                ->where('houseguest_id', $replacement->id)
                ->exists()) {
                throw ValidationException::withMessages(['houseguest' => __('This houseguest has already been drafted.')]);
            }

            $previousHouseguestId = $pick->houseguest_id;
            $pick->update(['houseguest_id' => $replacement->id]);

            $this->recordAuditLog->handle($pool, $actor, 'draft.pick_corrected', $pick, [
                'from_houseguest_id' => $previousHouseguestId,
                'to_houseguest_id' => $replacement->id,
                'reason' => $reason,
            ]);

            return $pick->fresh(['houseguest', 'poolMember.user']);
        }, attempts: 3);
    }
}
