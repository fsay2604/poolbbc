<?php

namespace App\Http\Requests\Admin;

use App\Enums\EventMode;
use App\Enums\ResultPublicationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStandardEventTypeRequest extends FormRequest
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
            'form.name' => ['required', 'string', 'max:120'],
            'form.question' => ['nullable', 'string', 'max:500'],
            'form.default_mode' => ['required', Rule::enum(EventMode::class)],
            'form.result_publication_mode' => ['required', Rule::enum(ResultPublicationMode::class)],
            'form.prediction_min_selections' => ['required', 'integer', 'between:0,20'],
            'form.prediction_max_selections' => ['required', 'integer', 'between:1,20', 'gte:form.prediction_min_selections'],
            'form.result_min_selections' => ['required', 'integer', 'between:0,20'],
            'form.result_max_selections' => ['required', 'integer', 'between:1,20', 'gte:form.result_min_selections'],
            'form.include_inactive_houseguests' => ['required', 'boolean'],
            'form.allow_none' => ['required', 'boolean'],
            'form.owner_points' => ['required', 'integer', 'between:-100,100'],
            'form.prediction_points' => ['required', 'integer', 'between:-100,100'],
            'form.exact_bonus' => ['required', 'integer', 'between:-100,100'],
            'form.wrong_penalty' => ['required', 'integer', 'between:-100,0'],
            'form.allow_negative' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'form.name' => __('standard event type name'),
            'form.question' => __('standard event type question'),
            'form.default_mode' => __('standard event type scoring mode'),
            'form.result_publication_mode' => __('standard event type result publication mode'),
            'form.prediction_min_selections' => __('minimum predictions'),
            'form.prediction_max_selections' => __('maximum predictions'),
            'form.result_min_selections' => __('minimum results'),
            'form.result_max_selections' => __('maximum results'),
            'form.owner_points' => __('owner points'),
            'form.prediction_points' => __('prediction points'),
            'form.exact_bonus' => __('exact match bonus'),
            'form.wrong_penalty' => __('wrong answer penalty'),
        ];
    }
}
