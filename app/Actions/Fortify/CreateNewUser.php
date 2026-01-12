<?php

namespace App\Actions\Fortify;

use App\Http\Requests\Fortify\CreateNewUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $request = new CreateNewUserRequest;
        $validated = Validator::make(
            $input,
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        )->validate();

        return User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
    }
}
