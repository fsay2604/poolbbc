<?php

namespace App\Http\Requests\Pools;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoundRequest extends FormRequest
{
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
        return [
            'roundForm.name' => ['required', 'string', 'max:120'],
            'roundForm.starts_at' => ['nullable', 'date'],
            'roundForm.ends_at' => ['nullable', 'date', 'after:roundForm.starts_at'],
        ];
    }
}
