<?php

namespace App\Models;

use App\Enums\PoolMemberRole;
use App\Enums\PoolMemberStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PoolMember extends Model
{
    /** @use HasFactory<\Database\Factories\PoolMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'pool_id', 'user_id', 'role', 'status', 'draft_position', 'joined_at', 'removed_at',
    ];

    protected $attributes = [
        'role' => PoolMemberRole::Member->value,
        'status' => PoolMemberStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'role' => PoolMemberRole::class,
            'status' => PoolMemberStatus::class,
            'joined_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function draftPicks(): HasMany
    {
        return $this->hasMany(DraftPick::class);
    }

    public function eventPredictions(): HasMany
    {
        return $this->hasMany(EventPrediction::class);
    }

    public function pointEntries(): HasMany
    {
        return $this->hasMany(PointEntry::class);
    }
}
