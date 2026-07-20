<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Http\Requests\Events\UpdateSeasonRoundRequest;
use App\Models\SeasonRound;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateSeasonRound
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param array<string, mixed> $data */
    public function handle(SeasonRound $round, User $administrator, array $data): SeasonRound
    {
        Gate::forUser($administrator)->authorize('update', $round);
        $request = new UpdateSeasonRoundRequest;
        $validated = Validator::make(
            ['roundForm' => $data],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        )->validate()['roundForm'];

        return DB::transaction(function () use ($round, $administrator, $validated): SeasonRound {
            $round = SeasonRound::query()->lockForUpdate()->findOrFail($round->id);
            Gate::forUser($administrator)->authorize('update', $round);
            $before = $this->snapshot($round);

            $round->update([
                'name' => trim($validated['name']),
                'starts_at' => filled($validated['starts_at'] ?? null)
                    ? Carbon::parse($validated['starts_at'], config('app.timezone'))
                    : null,
                'ends_at' => filled($validated['ends_at'] ?? null)
                    ? Carbon::parse($validated['ends_at'], config('app.timezone'))
                    : null,
            ]);

            $this->recordAuditLog->handle(null, $administrator, 'season_round.updated', $round, [
                'before' => $before,
                'after' => $this->snapshot($round),
            ]);

            return $round->fresh();
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(SeasonRound $round): array
    {
        return [
            'name' => $round->name,
            'starts_at' => $round->starts_at?->toISOString(),
            'ends_at' => $round->ends_at?->toISOString(),
        ];
    }
}
