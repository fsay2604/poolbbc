<?php

use App\Actions\Predictions\RecalculateAllScores;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public function recalculate(RecalculateAllScores $recalculateAllScores): void
    {
        Gate::authorize('admin');

        $admin = Auth::user();
        abort_if($admin === null, 403);

        $recalculateAllScores->run(admin: $admin);

        $this->dispatch('scores-recalculated');
    }
};
