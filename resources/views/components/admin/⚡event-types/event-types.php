<?php

use App\Actions\Events\UpdateStandardEventType;
use App\Http\Requests\Admin\UpdateStandardEventTypeRequest;
use App\Models\EventType;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public $eventTypes;

    /** @var array<int, array<string, mixed>> */
    public array $eventTypeConfigs = [];

    #[Locked]
    public ?int $editingEventTypeId = null;

    public bool $showEditModal = false;

    /** @var array<string, mixed> */
    public array $form = [
        'name' => '',
        'question' => '',
        'default_mode' => 'roster',
        'result_publication_mode' => 'immediate',
        'prediction_min_selections' => 1,
        'prediction_max_selections' => 1,
        'result_min_selections' => 1,
        'result_max_selections' => 1,
        'include_inactive_houseguests' => false,
        'allow_none' => false,
        'owner_points' => 0,
        'prediction_points' => 0,
        'exact_bonus' => 0,
        'wrong_penalty' => 0,
        'allow_negative' => false,
    ];

    public function mount(StandardEventTypeCatalog $catalog): void
    {
        Gate::authorize('viewAny', EventType::class);
        $this->refreshEventTypes($catalog);
    }

    public function edit(int $eventTypeId, StandardEventTypeCatalog $catalog): void
    {
        $eventType = $this->managedEventType($eventTypeId, $catalog);
        Gate::authorize('update', $eventType);
        $config = $catalog->normalizeDefaultConfig($eventType->slug, $eventType->default_config);

        $this->editingEventTypeId = $eventType->id;
        $this->form = [
            'name' => $eventType->name,
            'question' => $config['question'] ?? '',
            'default_mode' => $eventType->default_mode->value,
            'result_publication_mode' => $config['result_publication_mode'],
            'prediction_min_selections' => $config['prediction_min_selections'],
            'prediction_max_selections' => $config['prediction_max_selections'],
            'result_min_selections' => $config['result_min_selections'],
            'result_max_selections' => $config['result_max_selections'],
            'include_inactive_houseguests' => $config['include_inactive_houseguests'],
            'allow_none' => $config['allow_none'],
            'owner_points' => data_get($config, 'owner.points_per_match'),
            'prediction_points' => data_get($config, 'prediction.points_per_correct'),
            'exact_bonus' => data_get($config, 'prediction.exact_match_bonus'),
            'wrong_penalty' => data_get($config, 'prediction.wrong_answer_penalty'),
            'allow_negative' => $config['allow_negative'],
        ];
        $this->resetErrorBag();
        $this->showEditModal = true;
    }

    public function save(
        UpdateStandardEventType $updateStandardEventType,
        StandardEventTypeCatalog $catalog,
    ): void {
        abort_if($this->editingEventTypeId === null, 422);
        $eventType = $this->managedEventType($this->editingEventTypeId, $catalog);
        Gate::authorize('update', $eventType);
        $request = new UpdateStandardEventTypeRequest;
        $validated = $this->validate($request->rules(), $request->messages(), $request->attributes());

        $updateStandardEventType->handle($eventType, auth()->user(), $validated['form']);

        $this->showEditModal = false;
        $this->editingEventTypeId = null;
        $this->resetErrorBag();
        $this->refreshEventTypes($catalog);
        $this->dispatch('standard-event-type-updated');
    }

    public function cancelEdit(): void
    {
        $this->showEditModal = false;
        $this->editingEventTypeId = null;
        $this->resetErrorBag();
    }

    private function managedEventType(int $eventTypeId, StandardEventTypeCatalog $catalog): EventType
    {
        return EventType::query()
            ->whereKey($eventTypeId)
            ->whereNull('pool_id')
            ->where('is_standard', true)
            ->whereIn('slug', $catalog->slugs())
            ->firstOrFail();
    }

    private function refreshEventTypes(StandardEventTypeCatalog $catalog): void
    {
        $this->eventTypes = EventType::query()
            ->whereNull('pool_id')
            ->where('is_standard', true)
            ->whereIn('slug', $catalog->slugs())
            ->orderBy('name')
            ->get();
        $this->eventTypeConfigs = $this->eventTypes
            ->mapWithKeys(fn (EventType $eventType): array => [
                $eventType->id => $catalog->normalizeDefaultConfig($eventType->slug, $eventType->default_config),
            ])
            ->all();
    }
};
