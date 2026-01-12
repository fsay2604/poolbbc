<?php

use App\Http\Requests\Admin\SaveHouseguestRequest;
use App\Models\Houseguest;
use App\Models\Season;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use App\Enums\Occupation;

new class extends Component {
    use WithFileUploads;

    public ?Season $season = null;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Houseguest> */
    public $houseguests;

    public mixed $avatar = null;

    /** @var array{name:string,sex:?string,occupations:array<int,string>,avatar_url:?string,is_active:bool,sort_order:int} */
    public array $form = [
        'name' => '',
        'sex' => 'M',
        'occupations' => [],
        'avatar_url' => null,
        'is_active' => true,
        'sort_order' => 0,
    ];

    public ?int $editingId = null;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->season = Season::query()->where('is_active', true)->first();
        $this->refresh();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->avatar = null;
        $this->form = [
            'name' => '',
            'sex' => 'M',
            'occupations' => [],
            'avatar_url' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function edit(int $houseguestId): void
    {
        $houseguest = Houseguest::query()->findOrFail($houseguestId);

        $this->editingId = $houseguest->id;
        $this->avatar = null;
        $this->form = [
            'name' => $houseguest->name,
            'sex' => $houseguest->sex ?? 'M',
            'occupations' => is_array($houseguest->occupations) ? $houseguest->occupations : [],
            'avatar_url' => $houseguest->avatar_url,
            'is_active' => $houseguest->is_active,
            'sort_order' => $houseguest->sort_order,
        ];
    }

    public function save(): void
    {
        Gate::authorize('admin');
        abort_if($this->season === null, 422);

        $request = new SaveHouseguestRequest();
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $houseguest = $this->editingId
            ? Houseguest::query()->findOrFail($this->editingId)
            : new Houseguest(['season_id' => $this->season->id]);

        if ($this->avatar) {
            if ($houseguest->avatar_url) {
                Storage::disk('public')->delete($houseguest->avatar_url);
            }

            $validated['form']['avatar_url'] = $this->avatar->store('houseguests/avatars', 'public');
            $this->avatar = null;
        }

        $houseguest->fill(array_merge($validated['form'], ['season_id' => $this->season->id]));
        $houseguest->save();

        $this->startCreate();
        $this->refresh();

        $this->dispatch('houseguest-saved');
    }

    private function refresh(): void
    {
        $this->houseguests = Houseguest::query()
            ->when($this->season, fn ($q) => $q->where('season_id', $this->season->id), fn ($q) => $q->whereRaw('1=0'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="grid gap-1">
            <flux:heading size="xl" level="1">{{ __('Houseguests') }}</flux:heading>
            @if ($season)
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $season->name }}</div>
            @else
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active season (set one in Seasons).') }}</div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <flux:input wire:model="form.name" :label="__('Name')" required />

                    <flux:select wire:model="form.sex" :label="__('Sex')">
                        <option value="M">{{ __('Male') }}</option>
                        <option value="F">{{ __('Female') }}</option>
                    </flux:select>

                    <flux:pillbox wire:model="form.occupations" :label="__('Occupations')" placeholder="{{ __('Select occupations') }}" multiple searchable>
                        @foreach (Occupation::cases() as $occupation)
                            <flux:pillbox.option value="{{ $occupation->value }}">{{ __($occupation->value) }}</flux:pillbox.option>
                        @endforeach

                    </flux:pillbox>

                    <flux:file-upload wire:model="avatar">
                        <div class="flex items-center gap-4">
                            @if ($avatar)
                                <img src="{{ $avatar?->temporaryUrl() }}" class="size-10 rounded-full object-cover" />
                            @elseif ($form['avatar_url'])
                                <img src="{{ asset('storage/'.$form['avatar_url']) }}" class="size-10 rounded-full object-cover" />
                            @else
                                <flux:avatar :name="$form['name']" size="sm" circle />
                            @endif

                            <div class="grid gap-1">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Avatar') }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Upload an image (max 2MB).') }}</div>
                            </div>
                        </div>
                    </flux:file-upload>

                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="form.sort_order" :label="__('Sort order')" type="number" min="0" required />
                        <flux:switch wire:model="form.is_active" :label="__('Active')" />
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit" :disabled="! $season">{{ __('Save') }}</flux:button>
                        <x-action-message on="houseguest-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
                    </div>
                </form>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Avatar') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Name') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Sex') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Occupations') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Active') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($houseguests as $hg)
                                <tr>
                                    <td class="px-4 py-3">
                                        <flux:avatar :src="$hg->avatar_url ? asset('storage/'.$hg->avatar_url) : null" :name="$hg->name" size="sm" circle />
                                    </td>
                                    <td class="px-4 py-3">{{ $hg->name }}</td>
                                    <td class="px-4 py-3">{{ $hg->sex ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        {{ is_array($hg->occupations) && $hg->occupations !== [] ? implode(', ', array_map(fn ($occupation) => __($occupation), $hg->occupations)) : '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($hg->is_active)
                                            <span class="text-green-600">{{ __('Yes') }}</span>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <flux:button size="sm" type="button" wire:click="edit({{ $hg->id }})">{{ __('Edit') }}</flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
