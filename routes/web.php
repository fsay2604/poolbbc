<?php

use App\Actions\Dashboard\BuildDashboardStats;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::get('dashboard', function (BuildDashboardStats $dashboardStats) {
    return view('dashboard', $dashboardStats->handle(request()->user()));
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

    Route::livewire('pools', 'pools.index')->name('pools.index');
    Route::middleware('can:view,pool')->group(function () {
        Route::livewire('pools/{pool}', 'pools.show')->name('pools.show');
        Route::livewire('pools/{pool}/draft', 'pools.draft')->name('pools.draft');
        Route::livewire('pools/{pool}/predictions', 'pools.predictions')->name('pools.predictions');
        Route::livewire('pools/{pool}/events', 'pools.events')->name('pools.events');
        Route::livewire('pools/{pool}/leaderboard', 'pools.leaderboard')->name('pools.leaderboard');

        Route::middleware('can:admin')->group(function () {
            Route::livewire('pools/{pool}/official-rounds', 'admin.official-rounds')->name('pools.official-rounds');
            Route::livewire('pools/{pool}/official-results', 'admin.official-results')->name('pools.official-results');
        });
    });

    Route::middleware(['can:admin'])->prefix('admin')->group(function () {
        Route::livewire('seasons', 'admin.seasons.index')->name('admin.seasons.index');
        Route::livewire('event-types', 'admin.event-types')->name('admin.event-types');
        Route::livewire('official-rounds', 'admin.official-rounds')->name('admin.official-rounds');
        Route::livewire('official-results', 'admin.official-results')->name('admin.official-results');
        Route::livewire('houseguests', 'admin.houseguests.index')->name('admin.houseguests.index');
        Route::livewire('users', 'admin.users.index')->name('admin.users.index');
    });
});
