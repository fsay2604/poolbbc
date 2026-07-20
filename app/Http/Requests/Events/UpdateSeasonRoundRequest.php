<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeasonRoundRequest extends FormRequest
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
            'roundForm.name' => ['required', 'string', 'max:255'],
            'roundForm.starts_at' => ['nullable', 'date'],
            'roundForm.ends_at' => ['nullable', 'date', 'after:roundForm.starts_at'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'roundForm.name' => __('round name'),
            'roundForm.starts_at' => __('round start date'),
            'roundForm.ends_at' => __('round end date'),
        ];
    }
}
