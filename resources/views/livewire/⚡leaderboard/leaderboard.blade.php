<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Leaderboard') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
            @endif
        </div>

        @php
            $topRows = $rows->take(3);
            $restRows = $rows->slice(3);
            $medals = [
                0 => [
                    'label' => __('Gold'),
                    'badge' => 'bg-gradient-to-br from-amber-200 to-amber-400 text-amber-900 ring-amber-300/60',
                    'card' => 'border-amber-200/70 from-amber-50/80 to-white dark:border-amber-500/30 dark:from-amber-950/40 dark:to-zinc-900',
                ],
                1 => [
                    'label' => __('Silver'),
                    'badge' => 'bg-gradient-to-br from-slate-200 to-slate-400 text-slate-900 ring-slate-300/60',
                    'card' => 'border-slate-200/70 from-slate-50/80 to-white dark:border-slate-500/30 dark:from-slate-950/30 dark:to-zinc-900',
                ],
                2 => [
                    'label' => __('Bronze'),
                    'badge' => 'bg-gradient-to-br from-orange-200 to-orange-400 text-orange-900 ring-orange-300/60',
                    'card' => 'border-orange-200/70 from-orange-50/80 to-white dark:border-orange-500/30 dark:from-orange-950/30 dark:to-zinc-900',
                ],
            ];
        @endphp

        @if ($rows->isEmpty())
            <div class="rounded-xl border border-neutral-200 bg-white px-6 py-12 text-center text-zinc-500 dark:border-neutral-700 dark:bg-zinc-900 dark:text-zinc-400">
                {{ __('No scores yet.') }}
            </div>
        @else
            <div class="grid gap-6">
                <div class="grid gap-4 md:grid-cols-3">
                    @foreach ($topRows as $index => $row)
                        @php
                            $medal = $medals[$index] ?? $medals[2];
                        @endphp
                        <a
                            class="group w-full overflow-hidden rounded-2xl border bg-gradient-to-br p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900 {{ $medal['card'] }}"
                            href="{{ route('leaderboard.show', $row['user']) }}"
                            wire:navigate.hover
                        >
                            <div class="flex flex-col gap-3">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                                        {{ __('Place') }} {{ $index + 1 }}
                                    </div>
                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $medal['badge'] }}">
                                        {{ $medal['label'] }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between gap-4">
                                    <div class="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $row['user']->name }}
                                    </div>
                                    <div class="text-3xl font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $row['total'] }}
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                @if ($restRows->isNotEmpty())
                    <div class="grid gap-3">
                        @foreach ($restRows as $index => $row)
                            <a
                                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-neutral-200 bg-white px-4 py-3 text-sm transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:border-neutral-700 dark:bg-zinc-900 dark:hover:border-zinc-500 dark:focus-visible:ring-offset-zinc-900"
                                href="{{ route('leaderboard.show', $row['user']) }}"
                                wire:navigate.hover
                            >
                                <div class="flex items-center gap-3">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $index + 4 }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row['user']->name }}</div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $row['total'] }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
