<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

class SubmitEventPredictionRequest extends FormRequest
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
            'prediction.option_ids' => ['array'],
            'prediction.option_ids.*' => ['integer', 'distinct', 'exists:event_options,id'],
            'prediction.submit' => ['required', 'boolean'],
        ];
    }
}
