<?php

use App\Models\Week;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');

    Volt::route('weeks', 'weeks.index')->name('weeks.index');
    Volt::route('weeks/{week}', 'weeks.show')->name('weeks.show');

    Route::get('current-week', function () {
        $week = Week::query()
            ->forActiveSeason()
            ->orderBy('number')
            ->whereNull('locked_at')
            ->where('prediction_deadline_at', '>', now())
            ->first();

        $week ??= Week::query()->forActiveSeason()->orderByDesc('number')->first();

        abort_if($week === null, 404);

        return redirect()->route('weeks.show', $week);
    })->name('current-week');

    Volt::route('leaderboard', 'leaderboard')->name('leaderboard');

    Route::middleware(['can:admin'])->prefix('admin')->group(function () {
        Volt::route('seasons', 'admin.seasons.index')->name('admin.seasons.index');
        Volt::route('weeks', 'admin.weeks.index')->name('admin.weeks.index');
        Volt::route('houseguests', 'admin.houseguests.index')->name('admin.houseguests.index');
        Volt::route('weeks/{week}/outcome', 'admin.weeks.outcome')->name('admin.weeks.outcome');
        Volt::route('predictions/{prediction}', 'admin.predictions.edit')->name('admin.predictions.edit');
        Volt::route('recalculate', 'admin.recalculate')->name('admin.recalculate');
    });
});
