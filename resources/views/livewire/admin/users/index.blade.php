<?php

use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    /** @var \Illuminate\Support\Collection<int, \App\Models\User> */
    public $users;

    public mixed $avatar = null;

    /** @var array{name:string,avatar_url:?string,email:string,is_admin:bool,password:string,password_confirmation:string} */
    public array $form = [
        'name' => '',
        'avatar_url' => null,
        'email' => '',
        'is_admin' => false,
        'password' => '',
        'password_confirmation' => '',
    ];

    /** @var array{password:string,password_confirmation:string} */
    public array $resetPasswordForm = [
        'password' => '',
        'password_confirmation' => '',
    ];

    public ?int $editingId = null;

    public ?int $confirmingDeletionId = null;
    public ?string $confirmingDeletionName = null;
    public bool $showConfirmDeletionModal = false;

    public ?int $resettingPasswordId = null;
    public ?string $resettingPasswordName = null;
    public bool $showResetPasswordModal = false;

    public function mount(): void
    {
        Gate::authorize('admin');

        $this->refresh();
    }

    public function startCreate(): void
    {
        $this->editingId = null;
        $this->avatar = null;
        $this->form = [
            'name' => '',
            'avatar_url' => null,
            'email' => '',
            'is_admin' => false,
            'password' => '',
            'password_confirmation' => '',
        ];
    }

    public function edit(int $userId): void
    {
        Gate::authorize('admin');

        $user = User::query()->findOrFail($userId);

        $this->editingId = $user->id;
        $this->avatar = null;
        $this->form = [
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'password' => '',
            'password_confirmation' => '',
        ];
    }

    public function cancelEdit(): void
    {
        $this->startCreate();
    }

    public function save(): void
    {
        Gate::authorize('admin');
        $isCreating = $this->editingId === null;

        $request = (new SaveUserRequest())->setContext($this->editingId, $isCreating);
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $user = $isCreating
            ? new User()
            : User::query()->findOrFail($this->editingId);

        $user->fill([
            'name' => $validated['form']['name'],
            'email' => $validated['form']['email'],
        ]);

        if ($this->avatar) {
            if ($user->avatar_url) {
                Storage::disk('public')->delete($user->avatar_url);
            }

            $validated['form']['avatar_url'] = $this->avatar->store('users/avatars', 'public');
            $this->avatar = null;
        }

        if ($isCreating) {
            $user->forceFill(['password' => $validated['form']['password']]);
        }

        $user->forceFill([
            'is_admin' => $validated['form']['is_admin'],
            'avatar_url' => $validated['form']['avatar_url'] ?? $user->avatar_url,
        ]);
        $user->save();

        $this->startCreate();
        $this->refresh();
        $this->dispatch('user-saved');
    }

    public function toggleAdmin(int $userId): void
    {
        Gate::authorize('admin');
        abort_if(auth()->id() === $userId, 422);

        $user = User::query()->findOrFail($userId);
        $user->forceFill(['is_admin' => ! $user->is_admin])->save();

        $this->refresh();
        $this->dispatch('user-saved');
    }

    public function confirmDelete(int $userId): void
    {
        Gate::authorize('admin');
        abort_if(auth()->id() === $userId, 422);

        $user = User::query()->findOrFail($userId);
        $this->confirmingDeletionId = $user->id;
        $this->confirmingDeletionName = $user->name;
        $this->showConfirmDeletionModal = true;
    }

    public function deleteSelectedUser(): void
    {
        Gate::authorize('admin');
        abort_if($this->confirmingDeletionId === null, 422);
        abort_if(auth()->id() === $this->confirmingDeletionId, 422);

        $user = User::query()->findOrFail($this->confirmingDeletionId);
        $user->delete();

        if ($this->editingId === $user->id) {
            $this->cancelEdit();
        }

        $this->confirmingDeletionId = null;
        $this->confirmingDeletionName = null;
        $this->showConfirmDeletionModal = false;

        $this->refresh();
        $this->dispatch('user-deleted');
    }

    public function confirmResetPassword(int $userId): void
    {
        Gate::authorize('admin');

        $user = User::query()->findOrFail($userId);
        $this->resettingPasswordId = $user->id;
        $this->resettingPasswordName = $user->name;
        $this->resetPasswordForm = [
            'password' => '',
            'password_confirmation' => '',
        ];
        $this->showResetPasswordModal = true;
    }

    public function resetPassword(): void
    {
        Gate::authorize('admin');
        abort_if($this->resettingPasswordId === null, 422);

        $request = new ResetUserPasswordRequest();
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $user = User::query()->findOrFail($this->resettingPasswordId);
        $user->forceFill(['password' => $validated['resetPasswordForm']['password']])->save();

        $this->showResetPasswordModal = false;
        $this->resettingPasswordId = null;
        $this->resettingPasswordName = null;
        $this->resetPasswordForm = [
            'password' => '',
            'password_confirmation' => '',
        ];

        $this->dispatch('password-reset');
    }

    private function refresh(): void
    {
        $this->users = User::query()->orderBy('name')->get();
    }
}; ?>

<section class="w-full">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:button variant="primary" type="button" wire:click="startCreate">{{ __('New User') }}</flux:button>
        </div>

        <div class="grid gap-6">
            <div class="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-zinc-900">
                <form wire:submit="save" class="grid gap-4">
                    <div class="grid gap-4 grid-cols-2 md:items-end">
                        <flux:file-upload wire:model="avatar">
                            <div class="grid gap-2">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Avatar') }}</div>
                                <div class="flex items-center gap-3">
                                    @if ($avatar)
                                        <img src="{{ $avatar?->temporaryUrl() }}" class="size-12 rounded-full object-cover" />
                                    @elseif ($form['avatar_url'])
                                        <img src="{{ asset('storage/'.$form['avatar_url']) }}" class="size-12 rounded-full object-cover" />
                                    @else
                                        <flux:avatar :name="$form['name']" size="md" circle />
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Upload an image (max 2MB).') }}</div>
                            </div>
                        </flux:file-upload>
                        <flux:input wire:model="form.name" :label="__('Name')" class="col-span-10" required />
                    </div>
                    <flux:input wire:model="form.email" :label="__('Email')" type="email" required />
                    <flux:switch wire:model="form.is_admin" :label="__('Admin')" />

                    @if ($editingId === null)
                        <div class="grid gap-4 md:grid-cols-2">
                            <flux:input
                                wire:model="form.password"
                                type="password"
                                :label="__('Password')"
                                required
                                autocomplete="new-password"
                            />
                            <flux:input
                                wire:model="form.password_confirmation"
                                type="password"
                                :label="__('Confirm password')"
                                required
                                autocomplete="new-password"
                            />
                        </div>
                    @endif

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" type="submit">
                            {{ $editingId === null ? __('Create') : __('Save') }}
                        </flux:button>
                        <flux:button variant="filled" type="button" wire:click="cancelEdit">{{ __('Reset') }}</flux:button>
                        <x-action-message on="user-saved" class="text-sm">{{ __('Saved.') }}</x-action-message>
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
                                <th class="px-4 py-3 text-left font-medium">{{ __('Email') }}</th>
                                <th class="px-4 py-3 text-left font-medium">{{ __('Admin') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @foreach ($users as $user)
                                <tr>
                                    <td class="px-4 py-3">
                                        <flux:avatar :src="$user->avatar_url ? asset('storage/'.$user->avatar_url) : null" :name="$user->name" size="sm" circle />
                                    </td>
                                    <td class="px-4 py-3">{{ $user->name }}</td>
                                    <td class="px-4 py-3">{{ $user->email }}</td>
                                    <td class="px-4 py-3">
                                        @if ($user->is_admin)
                                            <span class="text-green-600">{{ __('Yes') }}</span>
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('No') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end">
                                            <flux:dropdown position="top" align="end">
                                                <flux:button size="sm" variant="filled" type="button">{{ __('Actions') }}</flux:button>
                                                <flux:menu>
                                                    <flux:menu.item wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:menu.item>
                                                    <flux:menu.item wire:click="toggleAdmin({{ $user->id }})">
                                                        {{ $user->is_admin ? __('Remove Admin') : __('Make Admin') }}
                                                    </flux:menu.item>
                                                    <flux:menu.item wire:click="confirmResetPassword({{ $user->id }})">
                                                        {{ __('Reset Password') }}
                                                    </flux:menu.item>
                                                    <flux:menu.separator />
                                                    <flux:menu.item variant="danger" wire:click="confirmDelete({{ $user->id }})">
                                                        {{ __('Delete') }}
                                                    </flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <x-action-message on="user-deleted" class="text-sm">{{ __('Deleted.') }}</x-action-message>
        <x-action-message on="password-reset" class="text-sm">{{ __('Password reset.') }}</x-action-message>
    </div>

    <flux:modal wire:model.self="showConfirmDeletionModal" focusable class="max-w-lg">
        <form wire:submit="deleteSelectedUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete user?') }}</flux:heading>

                <flux:subheading>
                    {{ __('This will deactivate the user and hide them from the app.') }}
                    @if ($confirmingDeletionName)
                        <div class="mt-2 font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $confirmingDeletionName }}
                        </div>
                    @endif
                </flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" :disabled="$confirmingDeletionId === null">
                    {{ __('Delete user') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showResetPasswordModal" focusable class="max-w-lg">
        <form wire:submit="resetPassword" class="grid gap-4">
            <div class="grid gap-2">
                <flux:heading size="lg">{{ __('Reset password') }}</flux:heading>
                @if ($resettingPasswordName)
                    <flux:subheading>{{ $resettingPasswordName }}</flux:subheading>
                @endif
            </div>

            <flux:input
                wire:model="resetPasswordForm.password"
                type="password"
                :label="__('New password')"
                required
                autocomplete="new-password"
            />
            <flux:input
                wire:model="resetPasswordForm.password_confirmation"
                type="password"
                :label="__('Confirm password')"
                required
                autocomplete="new-password"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" :disabled="$resettingPasswordId === null">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
