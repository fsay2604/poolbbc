<?php

namespace App\Http\Requests\SeasonPrediction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSeasonPredictionRequest extends FormRequest
{
    /**
     * @var list<int>
     */
    private array $houseguestIds = [];

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
        $inHouseguestIds = Rule::in($this->houseguestIds);

        return [
            'form.winner_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.first_evicted_houseguest_id'],
            'form.first_evicted_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.winner_houseguest_id'],

            'form.top_6_1_houseguest_id' => ['nullable', $inHouseguestIds],
            'form.top_6_2_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.top_6_1_houseguest_id'],
            'form.top_6_3_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id'],
            'form.top_6_4_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id'],
            'form.top_6_5_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id'],
            'form.top_6_6_houseguest_id' => ['nullable', $inHouseguestIds, 'different:form.top_6_1_houseguest_id', 'different:form.top_6_2_houseguest_id', 'different:form.top_6_3_houseguest_id', 'different:form.top_6_4_houseguest_id', 'different:form.top_6_5_houseguest_id'],
        ];
    }

    /**
     * @param  list<int>  $houseguestIds
     */
    public function setHouseguestIds(array $houseguestIds): self
    {
        $this->houseguestIds = $houseguestIds;

        return $this;
    }
}
