<?php

namespace App\Http\Requests\Admin;

use App\Enums\Occupation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveHouseguestRequest extends FormRequest
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
            'form.sex' => ['required', 'string', 'in:M,F'],
            'form.occupations' => ['array'],
            'form.occupations.*' => ['string', Rule::in(Occupation::values())],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'form.is_active' => ['required', 'boolean'],
            'form.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
