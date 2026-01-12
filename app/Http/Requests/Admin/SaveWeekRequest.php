<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveWeekRequest extends FormRequest
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
            'form.number' => ['required', 'integer', 'min:1'],
            'form.boss_count' => ['required', 'integer', 'min:1', 'max:20'],
            'form.nominee_count' => ['required', 'integer', 'min:1', 'max:20'],
            'form.evicted_count' => ['required', 'integer', 'min:1', 'max:20'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.is_locked' => ['required', 'boolean'],
            'form.auto_lock_at' => ['nullable', 'date'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date'],
        ];
    }
}
