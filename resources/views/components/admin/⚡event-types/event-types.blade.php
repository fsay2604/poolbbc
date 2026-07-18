<section class="w-full">
    <div class="flex flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">{{ __('Standard event types') }}</flux:heading>
            <flux:text class="mt-1">{{ __('These defaults are copied into future official events without changing existing rounds.') }}</flux:text>
        </div>

        <x-action-message on="standard-event-type-updated">{{ __('The standard event type has been updated.') }}</x-action-message>

        <flux:callout icon="information-circle" color="blue">
            <flux:callout.heading>{{ __('Stable templates') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Technical slugs and answer sources stay fixed so pool overrides and historical events remain compatible.') }}</flux:callout.text>
        </flux:callout>

        <flux:card class="overflow-hidden p-0">
            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Event type') }}</flux:table.column>
                        <flux:table.column>{{ __('Question') }}</flux:table.column>
                        <flux:table.column>{{ __('Selections') }}</flux:table.column>
                        <flux:table.column>{{ __('Scoring') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($eventTypes as $eventType)
                            @php($config = $eventTypeConfigs[$eventType->id])
                            <flux:table.row :key="$eventType->id" wire:key="standard-event-type-{{ $eventType->id }}">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $eventType->name }}</div>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        <flux:badge size="sm" color="zinc">{{ $eventType->slug }}</flux:badge>
                                        <flux:badge size="sm" color="blue">{{ $eventType->default_mode->label() }}</flux:badge>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="min-w-64 whitespace-normal">{{ $config['question'] }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="whitespace-nowrap">{{ __('Predictions: :minimum–:maximum', ['minimum' => $config['prediction_min_selections'], 'maximum' => $config['prediction_max_selections']]) }}</div>
                                    <div class="mt-1 whitespace-nowrap text-zinc-500">{{ __('Results: :minimum–:maximum', ['minimum' => $config['result_min_selections'], 'maximum' => $config['result_max_selections']]) }}</div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="whitespace-nowrap">{{ __('Owner: :points pts', ['points' => data_get($config, 'owner.points_per_match')]) }}</div>
                                    <div class="mt-1 whitespace-nowrap text-zinc-500">{{ __('Prediction: :points pts', ['points' => data_get($config, 'prediction.points_per_correct')]) }}</div>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $eventType->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </flux:card>

        <flux:modal wire:model.self="showEditModal" class="md:w-[46rem]">
            <form wire:submit="save" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Edit standard event type') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Only future event instances will inherit these values.') }}</flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.name" label="{{ __('Name') }}" />
                    <flux:select wire:model="form.default_mode" label="{{ __('Default scoring mode') }}">
                        @foreach (\App\Enums\EventMode::cases() as $mode)
                            <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.result_publication_mode" label="{{ __('Result publication') }}">
                        @foreach (\App\Enums\ResultPublicationMode::cases() as $publicationMode)
                            <flux:select.option :value="$publicationMode->value">{{ $publicationMode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="form.question" label="{{ __('Question') }}" rows="2" />
                    </div>
                </div>

                <div>
                    <flux:heading size="sm">{{ __('Selection limits') }}</flux:heading>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:input wire:model="form.prediction_min_selections" type="number" min="0" max="20" label="{{ __('Prediction minimum') }}" />
                        <flux:input wire:model="form.prediction_max_selections" type="number" min="1" max="20" label="{{ __('Prediction maximum') }}" />
                        <flux:input wire:model="form.result_min_selections" type="number" min="0" max="20" label="{{ __('Result minimum') }}" />
                        <flux:input wire:model="form.result_max_selections" type="number" min="1" max="20" label="{{ __('Result maximum') }}" />
                    </div>
                </div>

                <div>
                    <flux:heading size="sm">{{ __('Default scoring') }}</flux:heading>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="form.owner_points" type="number" min="-100" max="100" label="{{ __('Points per owned houseguest') }}" />
                        <flux:input wire:model="form.prediction_points" type="number" min="-100" max="100" label="{{ __('Points per correct prediction') }}" />
                        <flux:input wire:model="form.exact_bonus" type="number" min="-100" max="100" label="{{ __('Exact match bonus') }}" />
                        <flux:input wire:model="form.wrong_penalty" type="number" min="-100" max="0" label="{{ __('Wrong answer penalty') }}" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:switch wire:model="form.include_inactive_houseguests" label="{{ __('Include inactive houseguests') }}" />
                    <flux:switch wire:model="form.allow_none" label="{{ __('Allow an explicit no-houseguest option') }}" />
                    <flux:switch wire:model="form.allow_negative" label="{{ __('Allow negative totals') }}" />
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    </div>
</section>
