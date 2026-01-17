<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    private ?int $userId = null;

    private bool $isCreating = false;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'form.name' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'form.email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($this->userId),
            ],
            'form.is_admin' => ['required', 'boolean'],
        ];

        if ($this->isCreating) {
            $rules['form.password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        return $rules;
    }

    public function setContext(?int $userId, bool $isCreating): self
    {
        $this->userId = $userId;
        $this->isCreating = $isCreating;

        return $this;
    }
}
