<?php

use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
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
};
