<?php

use App\Models\Season;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

new class extends Component {
    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Week> */
    public $weeks;

    public function mount(): void
    {
        $this->season = Season::query()->where('is_active', true)->first();

        $userId = auth()->id();

        $this->weeks = Week::query()
            ->when(
                $this->season,
                fn (Builder $q) => $q->where('season_id', $this->season->id),
                fn (Builder $q) => $q->whereRaw('1=0'),
            )
            ->when($userId, fn (Builder $q) => $q->with(['predictions' => fn ($relation) => $relation->where('user_id', $userId)]))
            ->orderBy('number')
            ->get();
    }
};
