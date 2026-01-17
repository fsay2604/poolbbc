
<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid gap-1">
                <flux:heading size="xl" level="1">{{ __('Predictions') }}</flux:heading>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $user->name }}</div>
                @if ($season)
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
                @endif
            </div>
            <flux:button :href="route('dashboard')" wire:navigate.hover>
                {{ __('Back to dashboard') }}
            </flux:button>
        </div>

        @if (! $season)
            <div class="rounded-xl border border-neutral-200 bg-white px-6 py-12 text-center text-zinc-500 dark:border-neutral-700 dark:bg-zinc-900 dark:text-zinc-400">
                {{ __('No active season yet.') }}
            </div>
        @else
            @php
                $badgeBase = 'rounded-full px-3 py-1 text-xs font-medium';
                $badgeNeutral = $badgeBase.' bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200';
                $badgeGood = $badgeBase.' bg-emerald-200 text-emerald-900 dark:bg-emerald-400/20 dark:text-emerald-100';
            @endphp
            <div class="grid gap-6 lg:grid-cols-2">
                <flux:card>
                    <div class="flex items-center justify-between gap-4">
                        <div class="grid gap-1">
                            <flux:heading size="lg" level="2">{{ __('Season Prediction') }}</flux:heading>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('Season-wide picks and standings.') }}
                            </flux:text>
                        </div>
                        @if ($seasonPrediction?->confirmed_at)
                            <flux:badge>{{ __('Confirmed') }}</flux:badge>
                        @endif
                    </div>

                    @if (! $seasonPrediction)
                        <div class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('No season prediction submitted yet.') }}
                        </div>
                    @else
                        @php
                            $top6Ids = is_array($seasonPrediction->top_6_houseguest_ids)
                                ? $this->normalizeIdList($seasonPrediction->top_6_houseguest_ids)
                                : [];
                            $seasonTop6Ids = is_array($season->top_6_houseguest_ids)
                                ? $this->normalizeIdList($season->top_6_houseguest_ids)
                                : [];
                            $winnerCorrect = $this->isCorrectPick(
                                $seasonPrediction->winner_houseguest_id,
                                $season->winner_houseguest_id ? [$season->winner_houseguest_id] : [],
                            );
                            $firstEvictedCorrect = $this->isCorrectPick(
                                $seasonPrediction->first_evicted_houseguest_id,
                                $season->first_evicted_houseguest_id ? [$season->first_evicted_houseguest_id] : [],
                            );
                        @endphp

                        <div class="mt-4 grid gap-3 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Winner') }}</span>
                                <span class="{{ $winnerCorrect ? $badgeGood : $badgeNeutral }}">
                                    {{ $this->houseguestName($seasonPrediction->winner_houseguest_id) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('First evicted') }}</span>
                                <span class="{{ $firstEvictedCorrect ? $badgeGood : $badgeNeutral }}">
                                    {{ $this->houseguestName($seasonPrediction->first_evicted_houseguest_id) }}
                                </span>
                            </div>
                            <div class="flex flex-col gap-2">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Top 6') }}</span>
                                @if ($top6Ids !== [])
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($top6Ids as $id)
                                            <span class="{{ $this->isCorrectPick($id, $seasonTop6Ids) ? $badgeGood : $badgeNeutral }}">
                                                {{ $this->houseguestName($id) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">--</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </flux:card>

                <flux:card>
                    <div class="grid gap-1">
                        <flux:heading size="lg" level="2">{{ __('Weekly Predictions') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Per-week picks and results.') }}
                        </flux:text>
                    </div>

                    <div class="mt-4 grid gap-3 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Weeks with picks') }}</span>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $predictions->count() }} / {{ $weeks->count() }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Last updated') }}</span>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ optional($predictions->max('updated_at'))->format('Y-m-d H:i') ?? '--' }}
                            </span>
                        </div>
                    </div>
                </flux:card>
            </div>

            <div class="grid gap-4">
                @foreach ($weeks as $week)
                    @php
                        $prediction = $predictions->get($week->id);
                        $outcome = $week->outcome;
                        $bossIds = $this->bossIds($prediction);
                        $nomineeIds = $this->nomineeIds($prediction);
                        $evictedIds = $this->evictedIds($prediction);
                        $bossOutcomeIds = $this->outcomeBossIds($outcome);
                        $nomineeOutcomeIds = $this->outcomeNomineeIds($outcome);
                        $evictedOutcomeIds = $this->outcomeEvictedIds($outcome);
                        $vetoWinnerOutcomeIds = $outcome?->veto_winner_houseguest_id
                            ? [$outcome->veto_winner_houseguest_id]
                            : [];
                        $savedOutcomeIds = $outcome?->saved_houseguest_id ? [$outcome->saved_houseguest_id] : [];
                        $replacementOutcomeIds = $outcome?->replacement_nominee_houseguest_id
                            ? [$outcome->replacement_nominee_houseguest_id]
                            : [];
                    @endphp
                    <flux:card wire:key="prediction-week-{{ $week->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="grid gap-1">
                                <flux:heading size="lg" level="3">
                                    {{ $week->name ?? __('Week').' '.$week->number }}
                                </flux:heading>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $prediction?->confirmed_at ? __('Confirmed') : __('Unconfirmed') }}
                                </flux:text>
                            </div>
                            @if ($prediction?->score)
                                <div class="text-right">
                                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">
                                        {{ __('Points') }}
                                    </div>
                                    <div class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $prediction->score->points }}
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if (! $prediction)
                            <div class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('No prediction submitted yet.') }}
                            </div>
                        @else
                            <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                                <div class="grid gap-2">
                                    <div class="text-zinc-500 dark:text-zinc-400">{{ __('HOH (Boss)') }}</div>
                                    @if ($bossIds !== [])
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($bossIds as $id)
                                                <span class="{{ $this->isCorrectPick($id, $bossOutcomeIds) ? $badgeGood : $badgeNeutral }}">
                                                    {{ $this->houseguestName($id) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">--</span>
                                    @endif
                                </div>

                                <div class="grid gap-2">
                                    <div class="text-zinc-500 dark:text-zinc-400">{{ __('Nominees') }}</div>
                                    @if ($nomineeIds !== [])
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($nomineeIds as $id)
                                                <span class="{{ $this->isCorrectPick($id, $nomineeOutcomeIds) ? $badgeGood : $badgeNeutral }}">
                                                    {{ $this->houseguestName($id) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">--</span>
                                    @endif
                                </div>

                                <div class="grid gap-2">
                                    <div class="text-zinc-500 dark:text-zinc-400">{{ __('Veto') }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        <span class="{{ $this->isCorrectPick($prediction->veto_winner_houseguest_id, $vetoWinnerOutcomeIds) ? $badgeGood : $badgeNeutral }}">
                                            {{ $this->houseguestName($prediction->veto_winner_houseguest_id) }}
                                        </span>
                                        <span class="{{ $this->isCorrectBoolean($prediction->veto_used, $outcome?->veto_used) ? $badgeGood : $badgeNeutral }}">
                                            {{ $prediction->veto_used ? __('Used') : __('Not used') }}
                                        </span>
                                        @if ($prediction->veto_used)
                                            <span class="{{ $this->isCorrectPick($prediction->saved_houseguest_id, $savedOutcomeIds) ? $badgeGood : $badgeNeutral }}">
                                                {{ $this->houseguestName($prediction->saved_houseguest_id) }}
                                            </span>
                                            <span class="{{ $this->isCorrectPick($prediction->replacement_nominee_houseguest_id, $replacementOutcomeIds) ? $badgeGood : $badgeNeutral }}">
                                                {{ $this->houseguestName($prediction->replacement_nominee_houseguest_id) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="grid gap-2">
                                    <div class="text-zinc-500 dark:text-zinc-400">{{ __('Evicted') }}</div>
                                    @if ($evictedIds !== [])
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($evictedIds as $id)
                                                <span class="{{ $this->isCorrectPick($id, $evictedOutcomeIds) ? $badgeGood : $badgeNeutral }}">
                                                    {{ $this->houseguestName($id) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">--</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</section>
