<?php

use App\Http\Requests\Settings\DeleteUserRequest;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $request = new DeleteUserRequest();
        $this->validate($request->rules(), $request->messages(), $request->attributes());

        $user = Auth::user();
        tap($user, $logout(...));
        $user?->forceDelete();

        $this->redirect('/', navigate: true);
    }
};
