<?php

namespace App\Models;

use App\Enums\DraftMode;
use App\Enums\PoolMemberStatus;
use App\Enums\PoolStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pool extends Model
{
    /** @use HasFactory<\Database\Factories\PoolFactory> */
    use HasFactory;

    protected $fillable = [
        'season_id', 'owner_id', 'name', 'description', 'invite_code', 'timezone',
        'status', 'max_members', 'picks_per_member', 'draft_mode', 'exclusive_draft',
        'registrations_closed_at',
    ];

    protected $attributes = [
        'timezone' => 'America/Toronto',
        'status' => PoolStatus::Configuration->value,
        'max_members' => 12,
        'picks_per_member' => 1,
        'draft_mode' => DraftMode::Snake->value,
        'exclusive_draft' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => PoolStatus::class,
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

    public function memberFor(User $user): ?PoolMember
    {
        return $this->members()->whereBelongsTo($user)->first();
    }

    public function isManagedBy(User $user): bool
    {
        return $this->owner_id === $user->id
            || $this->members()->whereBelongsTo($user)->whereIn('role', ['owner', 'administrator'])->exists();
    }
}
