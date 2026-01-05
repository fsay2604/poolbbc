<x-layouts.app :title="__('Dashboard')">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Houseguests') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season yet.') }}</div>
            @endif
        </div>

        <div class="grid gap-3 grid-cols-2 sm:grid-cols-4 md:grid-cols-8">
            @foreach ($houseguests as $houseguest)
                <x-houseguest-card :houseguest="$houseguest" />
            @endforeach
        </div>

        <div class="grid gap-1">
            <flux:heading size="lg" level="2">{{ __('Statistics') }}</flux:heading>
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Overall prediction accuracy') }}</div>
        </div>

        @if (count($statistics) === 0)
            <flux:card>
                <div class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No scored predictions yet.') }}
                </div>
            </flux:card>
        @else
            <div class="flex gap-4 overflow-x-auto pb-1">
                @foreach ($statistics as $stat)
                    <flux:card class="min-w-[12rem] overflow-hidden">
                        <flux:text>{{ $stat['user_name'] }}</flux:text>

                        <flux:heading size="xl" class="mt-2 tabular-nums">
                            {{ $stat['accuracy'] }}%
                        </flux:heading>

                        <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $stat['earned'] }} / {{ $stat['possible'] }} points
                        </flux:text>

                        @if (is_array($stat['series']) && count($stat['series']) >= 2)
                            <flux:chart class="-mx-8 -mb-8 mt-3 h-[3rem]" :value="$stat['series']">
                                <flux:chart.svg gutter="0">
                                    <flux:chart.line class="text-accent" />
                                    <flux:chart.area class="text-accent/20" />
                                </flux:chart.svg>
                            </flux:chart>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
