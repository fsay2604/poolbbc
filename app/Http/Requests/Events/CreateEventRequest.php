<?php

namespace App\Http\Requests\Events;

use App\Enums\AnswerSource;
use App\Enums\EventMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEventRequest extends FormRequest
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
            'eventForm.round_id' => ['required', 'integer', Rule::exists('rounds', 'id')],
            'eventForm.event_type_id' => ['nullable', 'integer', Rule::exists('event_types', 'id')],
            'eventForm.name' => ['required', 'string', 'max:120'],
            'eventForm.question' => ['nullable', 'string', 'max:500'],
            'eventForm.mode' => ['required', Rule::enum(EventMode::class)],
            'eventForm.answer_source' => ['required', Rule::enum(AnswerSource::class)],
            'eventForm.opens_at' => ['nullable', 'date'],
            'eventForm.locks_at' => ['required', 'date', 'after:now'],
            'eventForm.prediction_min_selections' => ['required', 'integer', 'between:0,20'],
            'eventForm.prediction_max_selections' => ['required', 'integer', 'between:1,20', 'gte:eventForm.prediction_min_selections'],
            'eventForm.result_min_selections' => ['required', 'integer', 'between:0,20'],
            'eventForm.result_max_selections' => ['required', 'integer', 'between:1,20', 'gte:eventForm.result_min_selections'],
            'eventForm.owner_points' => ['required', 'integer', 'between:-100,100'],
            'eventForm.prediction_points' => ['required', 'integer', 'between:-100,100'],
            'eventForm.exact_bonus' => ['required', 'integer', 'between:-100,100'],
            'eventForm.wrong_penalty' => ['required', 'integer', 'between:-100,0'],
            'eventForm.allow_negative' => ['required', 'boolean'],
            'eventForm.allow_none' => ['required', 'boolean'],
            'customOptionsText' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
