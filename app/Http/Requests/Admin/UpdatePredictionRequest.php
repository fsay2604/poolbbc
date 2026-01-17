<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePredictionRequest extends FormRequest
{
    /**
     * @var list<int>
     */
    private array $houseguestIds = [];

    private int $bossCount = 1;

    private int $nomineeCount = 1;

    private int $evictedCount = 1;

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
        $rules = [
            'form.boss_houseguest_ids' => ['array'],
            'form.nominee_houseguest_ids' => ['array'],
            'form.evicted_houseguest_ids' => ['array'],
            'form.veto_winner_houseguest_id' => ['nullable', Rule::in($this->houseguestIds)],
            'form.veto_used' => ['nullable', 'boolean'],
            'form.saved_houseguest_id' => ['nullable', Rule::in($this->houseguestIds)],
            'form.replacement_nominee_houseguest_id' => ['nullable', Rule::in($this->houseguestIds)],
            'form.confirmed_at' => ['nullable', 'date'],
        ];

        for ($i = 0; $i < $this->bossCount; $i++) {
            $rules["form.boss_houseguest_ids.$i"] = ['nullable', Rule::in($this->houseguestIds), 'distinct'];
        }

        for ($i = 0; $i < $this->nomineeCount; $i++) {
            $rules["form.nominee_houseguest_ids.$i"] = ['nullable', Rule::in($this->houseguestIds), 'distinct'];
        }

        for ($i = 0; $i < $this->evictedCount; $i++) {
            $rules["form.evicted_houseguest_ids.$i"] = ['nullable', Rule::in($this->houseguestIds), 'distinct'];
        }

        return $rules;
    }

    /**
     * @param  list<int>  $houseguestIds
     */
    public function setContext(array $houseguestIds, int $bossCount, int $nomineeCount, int $evictedCount): self
    {
        $this->houseguestIds = $houseguestIds;
        $this->bossCount = max(1, $bossCount);
        $this->nomineeCount = max(1, $nomineeCount);
        $this->evictedCount = max(1, $evictedCount);

        return $this;
    }
}
