<?php

namespace App\Actions\Drafts;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\DraftMode;
use App\Enums\DraftStatus;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\Houseguest;
use App\Models\PoolMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MakeDraftPick
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function handle(Draft $draft, PoolMember $member, Houseguest $houseguest): DraftPick
    {
        return DB::transaction(function () use ($draft, $member, $houseguest): DraftPick {
            $draft = Draft::query()->lockForUpdate()->findOrFail($draft->id);
            $pool = $draft->pool()->with('season')->lockForUpdate()->firstOrFail();
            $member = PoolMember::query()->lockForUpdate()->findOrFail($member->id);
            $houseguest = Houseguest::query()->lockForUpdate()->findOrFail($houseguest->id);

            if ($draft->status !== DraftStatus::Active) {
                throw ValidationException::withMessages(['houseguest' => __('The draft is not active.')]);
            }

            if ($draft->current_pool_member_id !== $member->id
                || $member->pool_id !== $pool->id
                || $member->status !== PoolMemberStatus::Active) {
                throw ValidationException::withMessages(['houseguest' => __('It is not your turn to draft.')]);
            }

            if ($houseguest->season_id !== $pool->season_id || ! $houseguest->is_active) {
                throw ValidationException::withMessages(['houseguest' => __('This houseguest is not available.')]);
            }

            if (DraftPick::query()
                ->where('draft_id', $draft->id)
                ->where('pool_member_id', $member->id)
                ->where('houseguest_id', $houseguest->id)
                ->exists()) {
                throw ValidationException::withMessages(['houseguest' => __('This houseguest is already on this roster.')]);
            }

            if ($pool->exclusive_draft && DraftPick::query()->where('pool_id', $pool->id)->whereBelongsTo($houseguest)->exists()) {
                throw ValidationException::withMessages(['houseguest' => __('This houseguest has already been drafted.')]);
            }

            $members = $pool->activeMembers()->orderBy('draft_position')->get();
            $memberCount = $members->count();
            $pickNumber = $draft->current_pick_number;
            $roundNumber = intdiv($pickNumber - 1, $memberCount) + 1;

            $pick = DraftPick::query()->create([
                'draft_id' => $draft->id,
                'pool_id' => $pool->id,
                'pool_member_id' => $member->id,
                'houseguest_id' => $houseguest->id,
                'round_number' => $roundNumber,
                'pick_number' => $pickNumber,
                'exclusive_claim' => $pool->exclusive_draft ? 1 : null,
                'picked_at' => now(),
            ]);

            $nextPickNumber = $pickNumber + 1;
            $totalPicks = $memberCount * $pool->picks_per_member;

            if ($nextPickNumber > $totalPicks) {
                $before = [
                    'draft' => ['status' => $draft->status->value],
                    'pool' => ['status' => $pool->status->value],
                ];
                $draft->update([
                    'status' => DraftStatus::Completed,
                    'current_pool_member_id' => null,
                    'current_pick_number' => $pickNumber,
                    'completed_at' => now(),
                ]);
                $pool->update(['status' => PoolStatus::Active]);
                $this->recordAuditLog->handle($pool, $member->user()->firstOrFail(), 'draft.completed', $draft, [
                    'before' => $before,
                    'after' => [
                        'draft' => ['status' => $draft->status->value],
                        'pool' => ['status' => $pool->status->value],
                    ],
                ]);

                return $pick;
            }

            $nextRound = intdiv($nextPickNumber - 1, $memberCount) + 1;
            $indexInRound = ($nextPickNumber - 1) % $memberCount;
            $position = $pool->draft_mode === DraftMode::Snake && $nextRound % 2 === 0
                ? $memberCount - $indexInRound
                : $indexInRound + 1;
            $nextMember = $members->firstWhere('draft_position', $position);

            $draft->update([
                'current_pick_number' => $nextPickNumber,
                'current_pool_member_id' => $nextMember?->id,
                'turn_started_at' => now(),
            ]);

            return $pick;
        }, attempts: 3);
    }
}
