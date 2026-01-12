<?php

namespace App\Actions\Fortify;

use App\Http\Requests\Fortify\ResetUserPasswordRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        $request = new ResetUserPasswordRequest;
        $validated = Validator::make(
            $input,
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        )->validate();

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();
    }
}
