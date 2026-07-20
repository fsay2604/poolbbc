<?php

namespace App\Actions\Events;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\EventStatus;
use App\Enums\OfficialRoundTemplate;
use App\Models\EventType;
use App\Models\Season;
use App\Models\SeasonRound;
use App\Models\User;
use App\Support\StandardEventTypeCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateSeasonRoundFromTemplate
{
    public function __construct(
        private SynchronizeOfficialPoolEvents $synchronizeOfficialPoolEvents,
        private RecordAuditLog $recordAuditLog,
        private StandardEventTypeCatalog $catalog,
    ) {}

    public function handle(
        Season $season,
        User $administrator,
        OfficialRoundTemplate $template,
        string $name,
        ?Carbon $opensAt = null,
        ?Carbon $locksAt = null,
    ): SeasonRound {
        Gate::forUser($administrator)->authorize('create', SeasonRound::class);

        return DB::transaction(function () use ($season, $administrator, $template, $name, $opensAt, $locksAt): SeasonRound {
            $season = Season::query()->lockForUpdate()->findOrFail($season->id);
            if ($opensAt !== null && $opensAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'opens_at' => __('The opening date must be in the future.'),
                ]);
            }

            if ($locksAt !== null && $locksAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'locks_at' => __('The locking date must be in the future.'),
                ]);
            }

            if ($opensAt !== null && $locksAt !== null && $locksAt->lessThanOrEqualTo($opensAt)) {
                throw ValidationException::withMessages([
                    'locks_at' => __('The locking date must be after the opening date.'),
                ]);
            }

            $round = $season->canonicalRounds()->create([
                'name' => $name,
                'position' => ((int) $season->canonicalRounds()->max('position')) + 1,
                'status' => 'draft',
                'starts_at' => $opensAt,
                'ends_at' => $locksAt,
            ]);

            foreach ($this->catalog->composition($template) as $index => $item) {
                $catalogDefinition = $this->catalog->find($item['slug']);
                $eventType = EventType::query()->firstOrCreate(
                    ['scope_key' => 'global', 'slug' => $item['slug']],
                    [
                        'pool_id' => null,
                        'name' => $catalogDefinition['name'],
                        'is_standard' => true,
                        'default_mode' => $catalogDefinition['default_mode'],
                        'answer_source' => $catalogDefinition['answer_source'],
                        'default_config' => $catalogDefinition['default_config'],
                    ],
                );
                $event = $round->events()->create([
                    ...$this->catalog->seasonEventAttributes($eventType),
                    ...$item['overrides'],
                    'event_type_id' => $eventType->id,
                    'created_by' => $administrator->id,
                    'position' => $index + 1,
                    'status' => EventStatus::Draft,
                    'opens_at' => $opensAt,
                    'locks_at' => $locksAt,
                ]);
                $this->synchronizeOfficialPoolEvents->handleEvent($event);
            }

            $this->recordAuditLog->handle(null, $administrator, 'season_round.created', $round, [
                'season_id' => $season->id,
                'template' => $template->value,
                'event_ids' => $round->events()->pluck('id')->all(),
            ]);

            return $round->fresh('events');
        }, attempts: 3);
    }
}
