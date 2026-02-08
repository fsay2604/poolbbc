<?php

namespace App\Http\Requests\Admin;

use App\Actions\Weeks\WeekPhaseManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $phaseTypes = app(WeekPhaseManager::class)->phaseTypes();

        return [
            'form.number' => ['required', 'integer', 'min:1'],
            'form.phases' => ['required', 'array', 'list', 'min:1'],
            'form.phases.*.id' => ['nullable', 'integer'],
            'form.phases.*.position' => ['required', 'integer', 'min:1', 'distinct'],
            'form.phases.*.type' => ['required', Rule::in($phaseTypes)],
            'form.phases.*.hoh_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'form.phases.*.nominee_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'form.phases.*.winner_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'form.phases.*.saved_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'form.phases.*.replacement_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'form.phases.*.evicted_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.is_locked' => ['required', 'boolean'],
            'form.auto_lock_at' => ['nullable', 'date'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date'],
        ];
    }
}
