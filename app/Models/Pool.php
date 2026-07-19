<?php

namespace App\Models;

use App\Enums\DraftMode;
use App\Enums\EventMode;
use App\Enums\PoolCompetitionMode;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Pool extends Model
{
    /** @use HasFactory<\Database\Factories\PoolFactory> */
    use HasFactory;

    protected $fillable = [
        'season_id', 'owner_id', 'name', 'description', 'invite_code', 'timezone',
        'status', 'competition_mode', 'scoring_config', 'max_members', 'picks_per_member', 'draft_mode', 'exclusive_draft',
        'registrations_closed_at',
    ];

    protected $attributes = [
        'timezone' => 'America/Toronto',
        'status' => PoolStatus::Configuration->value,
        'competition_mode' => PoolCompetitionMode::Hybrid->value,
        'max_members' => 12,
        'picks_per_member' => 1,
        'draft_mode' => DraftMode::Snake->value,
        'exclusive_draft' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => PoolStatus::class,
            'competition_mode' => PoolCompetitionMode::class,
            'scoring_config' => 'array',
            'draft_mode' => DraftMode::class,
            'exclusive_draft' => 'boolean',
            'registrations_closed_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(PoolMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', PoolMemberStatus::Active->value);
    }

    public function competitionMembers(): HasMany
    {
        return $this->members()->where(function (Builder $query): void {
            $query->where('status', PoolMemberStatus::Active->value)
                ->orWhereHas('draftPicks')
                ->orWhereHas('eventPredictions')
                ->orWhereHas('poolEventPredictions')
                ->orWhereHas('pointEntries');
        });
    }

    public function draft(): HasOne
    {
        return $this->hasOne(Draft::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    public function poolEvents(): HasMany
    {
        return $this->hasMany(PoolEvent::class);
    }

    public function memberFor(User $user): ?PoolMember
    {
        return $this->members()->whereBelongsTo($user)->first();
    }

    public function isManagedBy(User $user): bool
    {
        return $this->members()
            ->whereBelongsTo($user)
            ->where('status', PoolMemberStatus::Active->value)
            ->whereIn('role', ['owner', 'administrator'])
            ->exists();
    }

    public function usesDraft(): bool
    {
        return in_array($this->competition_mode, [PoolCompetitionMode::RosterOnly, PoolCompetitionMode::Hybrid], true);
    }

    public function usesPredictions(): bool
    {
        return in_array($this->competition_mode, [PoolCompetitionMode::PredictionOnly, PoolCompetitionMode::Hybrid], true);
    }

    public function supportsEventMode(EventMode $mode): bool
    {
        return match ($this->competition_mode) {
            PoolCompetitionMode::PredictionOnly => $mode === EventMode::Prediction,
            PoolCompetitionMode::RosterOnly => $mode === EventMode::Roster,
            PoolCompetitionMode::Hybrid => true,
        };
    }

    public function isReadOnly(): bool
    {
        return in_array($this->status, [PoolStatus::Completed, PoolStatus::Archived], true);
    }

    public static function assertAcceptsMutations(int ...$poolIds): void
    {
        $poolIds = array_values(array_unique(array_filter($poolIds)));

        if ($poolIds !== [] && static::query()
            ->whereKey($poolIds)
            ->whereIn('status', [PoolStatus::Completed->value, PoolStatus::Archived->value])
            ->exists()) {
            throw ValidationException::withMessages([
                'pool' => __('Completed and archived pools are read-only.'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public static function defaultScoringConfig(): array
    {
        return [
            'allow_negative' => false,
            'prediction' => [
                'points_per_correct' => 2,
                'exact_match_bonus' => 0,
                'wrong_answer_penalty' => 0,
            ],
            'event_types' => [
                'head-of-household' => ['owner_points' => 5],
                'nomination' => ['owner_points' => 2],
                'veto-winner' => ['owner_points' => 3],
                'eviction' => ['owner_points' => -2],
                'season-winner' => ['owner_points' => 10],
            ],
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $pool): void {
            $originalStatus = PoolStatus::from($pool->getRawOriginal('status'));
            if ($originalStatus === PoolStatus::Archived
                || ($originalStatus === PoolStatus::Completed
                    && (! $pool->isDirty('status')
                        || $pool->status !== PoolStatus::Archived
                        || count($pool->getDirty()) !== 1))) {
                throw ValidationException::withMessages([
                    'pool' => __('Completed and archived pools are read-only.'),
                ]);
            }

            $criticalAttributes = [
                'season_id',
                'competition_mode',
                'scoring_config',
                'max_members',
                'picks_per_member',
                'draft_mode',
                'exclusive_draft',
            ];

            if ($pool->isDirty($criticalAttributes)
                && ! in_array($originalStatus, [PoolStatus::Configuration, PoolStatus::Registration], true)) {
                throw ValidationException::withMessages([
                    'pool' => __('Critical pool settings cannot change after the draft has started.'),
                ]);
            }
        });

        static::deleting(function (self $pool): void {
            if ($pool->status !== PoolStatus::Configuration) {
                throw ValidationException::withMessages([
                    'pool' => __('A pool cannot be deleted after registrations have opened.'),
                ]);
            }
        });
    }
}
