<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Leaderboard') }}</flux:heading>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->name }}</div>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
                @endif
            </div>
            <a
                class="inline-flex items-center gap-2 rounded-full border border-neutral-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition hover:border-zinc-300 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:border-neutral-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-500 dark:hover:text-zinc-100 dark:focus-visible:ring-offset-zinc-900"
                href="{{ route('leaderboard') }}"
                wire:navigate.hover
            >
                {{ __('Back to leaderboard') }}
            </a>
        </div>

        @if (! $season)
            <div class="rounded-xl border border-neutral-200 bg-white px-6 py-12 text-center text-zinc-500 dark:border-neutral-700 dark:bg-zinc-900 dark:text-zinc-400">
                {{ __('No scores yet.') }}
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</div>
                    <div class="mt-2 text-3xl font-semibold text-zinc-900 dark:text-zinc-100">{{ $row['total'] }}</div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ __('Season') }}</div>
                    <div class="mt-2 text-3xl font-semibold text-zinc-900 dark:text-zinc-100">{{ $row['season'] }}</div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ __('Weekly') }}</div>
                    <div class="mt-2 text-3xl font-semibold text-zinc-900 dark:text-zinc-100">{{ array_sum($row['by_week']) }}</div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Prediction') }}</th>
                                <th class="px-4 py-3 text-right font-medium">{{ __('Points') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr>
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ __('Season') }}</td>
                                <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row['season'] }}</td>
                            </tr>
                            @foreach ($weeks as $week)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ __('W').$week->number }}</td>
                                    <td class="px-4 py-3 text-right text-zinc-600 dark:text-zinc-300">
                                        {{ $row['by_week'][$week->id] ?? 0 }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</section>
