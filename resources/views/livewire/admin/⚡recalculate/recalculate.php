<?php

use App\Actions\Dashboard\BuildDashboardStats;
use App\Actions\Predictions\RecalculateAllScores;
use App\Models\Season;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public function recalculate(RecalculateAllScores $recalculateAllScores, BuildDashboardStats $dashboardStats): void
    {
        Gate::authorize('admin');

        $admin = Auth::user();
        abort_if($admin === null, 403);

        $recalculateAllScores->run(admin: $admin);

        $season = Season::query()->where('is_active', true)->first();
        $dashboardStats->forget($season);

        $this->dispatch('scores-recalculated');
    }
};
