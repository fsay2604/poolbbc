<?php

namespace App\Http\Requests\Events;

use App\Enums\EventMode;
use App\Enums\ResultPublicationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSeasonEventRequest extends FormRequest
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
            'eventForm.name' => ['required', 'string', 'max:255'],
            'eventForm.question' => ['nullable', 'string', 'max:1000'],
            'eventForm.default_mode' => ['required', Rule::enum(EventMode::class)],
            'eventForm.opens_at' => ['required', 'date', 'after:now'],
            'eventForm.locks_at' => ['required', 'date', 'after:eventForm.opens_at'],
            'eventForm.prediction_min_selections' => ['required', 'integer', 'between:0,20'],
            'eventForm.prediction_max_selections' => ['required', 'integer', 'between:1,20', 'gte:eventForm.prediction_min_selections'],
            'eventForm.result_min_selections' => ['required', 'integer', 'between:0,20'],
            'eventForm.result_max_selections' => ['required', 'integer', 'between:1,20', 'gte:eventForm.result_min_selections'],
            'eventForm.result_publication_mode' => ['required', Rule::enum(ResultPublicationMode::class)],
            'eventForm.include_inactive_houseguests' => ['required', 'boolean'],
            'eventForm.allow_none' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'eventForm.name' => __('event name'),
            'eventForm.question' => __('event question'),
            'eventForm.opens_at' => __('opening date'),
            'eventForm.locks_at' => __('locking date'),
        ];
    }
}
