<?php

use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Actions\Pools\ListUserPools;
use App\Models\EventType;
use App\Models\Pool;
use App\Models\SeasonRound;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    /** @var list<array{value:string,label:string}> */
    public array $roundOptions = [];

    public $eventTypes;

    public string $selectedRound = '';

    public string $selectedEventTypeId = '';

    public $availablePools;

    public function mount(Pool $pool): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool->load('season');
        $this->availablePools = app(ListUserPools::class)->handle(auth()->user());
        $this->loadFilters();
    }

    public function updatedSelectedRound(): void
    {
        unset($this->rows);
    }

    public function updatedSelectedEventTypeId(): void
    {
        unset($this->rows);
    }

    private function loadFilters(): void
    {
        $officialRounds = SeasonRound::query()
            ->where('season_id', $this->pool->season_id)
            ->whereHas('events.poolEvents', fn ($query) => $query->where('pool_id', $this->pool->id))
            ->orderBy('position')
            ->get()
            ->map(fn (SeasonRound $round): array => ['value' => 'official:'.$round->id, 'label' => $round->name]);
        $localRounds = $this->pool->rounds()
            ->orderBy('position')
            ->get()
            ->map(fn ($round): array => ['value' => 'local:'.$round->id, 'label' => $round->name]);
        $this->roundOptions = $officialRounds->concat($localRounds)->values()->all();

        $poolId = $this->pool->id;
        $this->eventTypes = EventType::query()
            ->where(fn ($query) => $query
                ->whereHas('events', fn ($eventQuery) => $eventQuery->where('pool_id', $poolId))
                ->orWhereHas('seasonEvents.poolEvents', fn ($poolEventQuery) => $poolEventQuery->where('pool_id', $poolId)))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, array{rank:int,rank_change:?int,member:\App\Models\PoolMember,total_points:int,active_houseguests:int}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(BuildPoolLeaderboard::class)->handle($this->pool, [
            'round' => $this->selectedRound,
            'event_type_id' => filled($this->selectedEventTypeId) ? (int) $this->selectedEventTypeId : null,
        ]);
    }
};
