<?php

namespace App\Models;

use App\Actions\Weeks\WeekPhaseManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class WeekOutcome extends Model
{
    /** @use HasFactory<\Database\Factories\WeekOutcomeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'week_id',
        'phase_results',
        'last_admin_edited_by_user_id',
        'last_admin_edited_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase_results' => 'array',
            'boss_houseguest_ids' => 'array',
            'nominee_houseguest_ids' => 'array',
            'evicted_houseguest_ids' => 'array',
            'veto_used' => 'boolean',
            'last_admin_edited_at' => 'datetime',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $outcome): void {
            $outcome->hydratePhaseResultsFromLegacyAttributesIfNeeded();
            $outcome->removeMissingLegacyColumnsFromAttributes();
        });
    }

    private function hydratePhaseResultsFromLegacyAttributesIfNeeded(): void
    {
        if (is_array($this->phase_results) && $this->phase_results !== []) {
            return;
        }

        $legacyColumns = $this->legacyColumns();
        $attributes = $this->getAttributes();
        $hasLegacyAttributes = false;

        foreach ($legacyColumns as $column) {
            if (array_key_exists($column, $attributes)) {
                $hasLegacyAttributes = true;

                break;
            }
        }

        if (! $hasLegacyAttributes || ! is_numeric($this->week_id)) {
            return;
        }

        $week = $this->relationLoaded('week')
            ? $this->getRelation('week')
            : Week::query()->with('phases')->find((int) $this->week_id);

        if (! $week instanceof Week) {
            return;
        }

        $payload = app(WeekPhaseManager::class)->legacyPayloadForModel($this, $week->phases);
        $this->phase_results = $payload;
    }

    private function removeMissingLegacyColumnsFromAttributes(): void
    {
        foreach ($this->legacyColumns() as $column) {
            if (Schema::hasColumn($this->getTable(), $column)) {
                continue;
            }

            if (array_key_exists($column, $this->getAttributes())) {
                unset($this->{$column});
            }
        }
    }

    /**
     * @return list<string>
     */
    private function legacyColumns(): array
    {
        return [
            'hoh_houseguest_id',
            'boss_houseguest_ids',
            'nominee_1_houseguest_id',
            'nominee_2_houseguest_id',
            'nominee_houseguest_ids',
            'veto_winner_houseguest_id',
            'veto_used',
            'saved_houseguest_id',
            'replacement_nominee_houseguest_id',
            'evicted_houseguest_id',
            'evicted_houseguest_ids',
        ];
    }
}
