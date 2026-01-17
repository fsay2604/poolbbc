<?php

use App\Actions\Dashboard\BuildDashboardStats;
use App\Models\Week;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::get('dashboard', function (BuildDashboardStats $dashboardStats) {
    return view('dashboard', $dashboardStats->handle());
})
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'settings.profile')->name('profile.edit');
    Route::livewire('settings/password', 'settings.password')->name('user-password.edit');
    Route::livewire('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Route::livewire('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    Route::livewire('weeks', 'weeks.index')->name('weeks.index');
    Route::livewire('weeks/{week}', 'weeks.show')->name('weeks.show');

    Route::livewire('season-prediction', 'season-prediction')->name('season.prediction');
    Route::livewire('predictions/{user}', 'predictions.show')->name('predictions.show');

    Route::get('current-week', function () {
        $now = now();

        $week = Week::query()
            ->forActiveSeason()
            ->orderBy('number')
            ->where(function ($query) use ($now) {
                $query
                    ->where('is_locked', false)
                    ->where(fn ($query) => $query->whereNull('auto_lock_at')->orWhere('auto_lock_at', '>', $now));
            })
            ->first();

        $week ??= Week::query()->forActiveSeason()->orderByDesc('number')->first();

        abort_if($week === null, 404);

        return redirect()->route('weeks.show', $week);
    })->name('current-week');

    Route::livewire('leaderboard', 'leaderboard')->name('leaderboard');

    Route::middleware(['can:admin'])->prefix('admin')->group(function () {
        Route::livewire('seasons', 'admin.seasons.index')->name('admin.seasons.index');
        Route::livewire('season-outcome', 'admin.seasons.outcome')->name('admin.seasons.outcome');
        Route::livewire('weeks', 'admin.weeks.index')->name('admin.weeks.index');
        Route::livewire('houseguests', 'admin.houseguests.index')->name('admin.houseguests.index');
        Route::livewire('users', 'admin.users.index')->name('admin.users.index');
        Route::livewire('weeks/{week}/outcome', 'admin.weeks.outcome')->name('admin.weeks.outcome');
        Route::livewire('predictions/{prediction}', 'admin.predictions.edit')->name('admin.predictions.edit');
    });
});
