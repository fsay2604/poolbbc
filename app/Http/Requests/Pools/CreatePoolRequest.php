<?php

namespace App\Http\Requests\Pools;

use App\Enums\DraftMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePoolRequest extends FormRequest
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
            'form.season_id' => ['required', 'integer', Rule::exists('seasons', 'id')],
            'form.name' => ['required', 'string', 'max:120'],
            'form.description' => ['nullable', 'string', 'max:1000'],
            'form.timezone' => ['required', 'timezone:all'],
            'form.max_members' => ['required', 'integer', 'between:1,50'],
            'form.picks_per_member' => ['required', 'integer', 'between:1,20'],
            'form.draft_mode' => ['required', Rule::enum(DraftMode::class)],
            'form.exclusive_draft' => ['required', 'boolean'],
        ];
    }
}
