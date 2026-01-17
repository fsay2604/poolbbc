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