<?php

namespace App\Actions\Seasons;

use App\Models\Season;
use Illuminate\Support\Carbon;

class CreateDefaultWeeks
{
    public function run(Season $season): void
    {
        if ($season->weeks()->exists()) {
            return;
        }

        $year = $season->starts_on?->year
            ?? $season->created_at?->year
            ?? now()->year;

        $januaryFirst = Carbon::create($year, 1, 1)->startOfDay();

        $daysUntilSunday = (Carbon::SUNDAY - $januaryFirst->dayOfWeek + 7) % 7;
        $secondSunday = $januaryFirst->copy()->addDays($daysUntilSunday)->addWeek();

        /** @var array<int, array<string, mixed>> $weeks */
        $weeks = [];

        for ($number = 1; $number <= 12; $number++) {
            $startsAt = $secondSunday->copy()->addWeeks($number - 1);

            $weeks[] = [
                'number' => $number,
                'name' => null,
                'is_locked' => true,
                'auto_lock_at' => $startsAt->copy()->addDays(6)->setTime(19, 0),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addWeek(),
            ];
        }

        $season->weeks()->createMany($weeks);
    }
}
