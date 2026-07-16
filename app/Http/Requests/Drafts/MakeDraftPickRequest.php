<?php

namespace App\Http\Requests\Drafts;

use Illuminate\Foundation\Http\FormRequest;

class MakeDraftPickRequest extends FormRequest
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
            'houseguest_id' => ['required', 'integer', 'exists:houseguests,id'],
        ];
    }
}
