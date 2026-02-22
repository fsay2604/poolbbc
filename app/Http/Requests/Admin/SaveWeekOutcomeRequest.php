<?php

namespace App\Http\Requests\Admin;

use App\Actions\Weeks\WeekPhaseManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWeekOutcomeRequest extends FormRequest
{
    /**
     * @var list<int>
     */
    private array $houseguestIds = [];

    /**
     * @var list<array{phase_id:int, type:string, lists:array<string, int>}>
     */
    private array $phaseDefinitions = [];

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
            'form.phases' => ['required', 'array', 'list', 'size:'.count($this->phaseDefinitions)],
        ];

        foreach ($this->phaseDefinitions as $index => $phaseDefinition) {
            $isVetoPhase = $phaseDefinition['type'] === WeekPhaseManager::TYPE_VETO;
            $rules["form.phases.$index.phase_id"] = ['required', 'integer', Rule::in([$phaseDefinition['phase_id']])];
            $rules["form.phases.$index.type"] = ['required', Rule::in([$phaseDefinition['type']])];

            if ($isVetoPhase) {
                $rules["form.phases.$index.veto_used"] = ['required', 'boolean'];
            }

            foreach ($phaseDefinition['lists'] as $listKey => $count) {
                $rules["form.phases.$index.$listKey"] = ['present', 'array', 'list', 'size:'.$count];

                for ($listIndex = 0; $listIndex < $count; $listIndex++) {
                    $rules["form.phases.$index.$listKey.$listIndex"] = [
                        'nullable',
                        Rule::in($this->houseguestIds),
                        'distinct',
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * @param  list<int>  $houseguestIds
     * @param  list<array{phase_id:int, type:string, lists:array<string, int>}>  $phaseDefinitions
     */
    public function setContext(array $houseguestIds, array $phaseDefinitions): self
    {
        $this->houseguestIds = $houseguestIds;
        $this->phaseDefinitions = $phaseDefinitions;

        return $this;
    }
}
