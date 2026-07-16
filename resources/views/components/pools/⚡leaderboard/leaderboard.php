<?php

use App\Actions\Leaderboards\BuildPoolLeaderboard;
use App\Models\Pool;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component
{
    public Pool $pool;

    public $rows;

    public function mount(Pool $pool, BuildPoolLeaderboard $buildPoolLeaderboard): void
    {
        Gate::authorize('view', $pool);
        $this->pool = $pool->load('season');
        $this->rows = $buildPoolLeaderboard->handle($pool);
    }
};
