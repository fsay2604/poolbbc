<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftPick extends Model
{
    /** @use HasFactory<\Database\Factories\DraftPickFactory> */
    use HasFactory;

    protected $fillable = [
        'draft_id', 'pool_id', 'pool_member_id', 'houseguest_id', 'round_number',
        'pick_number', 'exclusive_claim', 'picked_at',
    ];

    protected function casts(): array
    {
        return ['picked_at' => 'datetime'];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class);
    }

    public function houseguest(): BelongsTo
    {
        return $this->belongsTo(Houseguest::class);
    }
}
