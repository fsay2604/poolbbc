<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveSeasonRequest extends FormRequest
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
            'form.name' => ['required', 'string', 'max:255'],
            'form.is_active' => ['required', 'boolean'],
            'form.starts_on' => ['nullable', 'date'],
            'form.ends_on' => ['nullable', 'date'],
        ];
    }
}
