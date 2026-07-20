<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Http\Requests\Pools\CreateRoundRequest;
use App\Models\Pool;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CreateRound
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /** @param array<string, mixed> $data */
    public function handle(Pool $pool, User $manager, array $data): Round
    {
        Gate::forUser($manager)->authorize('update', $pool);
        $request = new CreateRoundRequest;
        $validated = Validator::make(
            ['roundForm' => $data],
            $request->rules(),
        )->validate()['roundForm'];

        return DB::transaction(function () use ($pool, $manager, $validated): Round {
            $pool = Pool::query()->lockForUpdate()->findOrFail($pool->id);
            Gate::forUser($manager)->authorize('update', $pool);

            $round = $pool->rounds()->create([
                'name' => trim($validated['name']),
                'position' => ((int) $pool->rounds()->max('position')) + 1,
                'status' => 'draft',
                'starts_at' => filled($validated['starts_at'] ?? null)
                    ? Carbon::parse($validated['starts_at'], config('app.timezone'))
                    : null,
                'ends_at' => filled($validated['ends_at'] ?? null)
                    ? Carbon::parse($validated['ends_at'], config('app.timezone'))
                    : null,
            ]);

            $this->recordAuditLog->handle($pool, $manager, 'round.created', $round);

            return $round->fresh();
        }, attempts: 3);
    }
}
