<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolScoreProjection extends Model
{
    /** @use HasFactory<\Database\Factories\PoolScoreProjectionFactory> */
    use HasFactory;

    protected $fillable = ['pool_id', 'pool_member_id', 'total_points', 'last_point_entry_id', 'rebuilt_at'];

    protected function casts(): array
    {
        return ['rebuilt_at' => 'datetime'];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function poolMember(): BelongsTo
    {
        return $this->belongsTo(PoolMember::class);
    }

    public function lastPointEntry(): BelongsTo
    {
        return $this->belongsTo(PointEntry::class, 'last_point_entry_id');
    }
}
