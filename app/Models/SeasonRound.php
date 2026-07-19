<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeasonRound extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonRoundFactory> */
    use HasFactory;

    protected $fillable = ['season_id', 'name', 'position', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SeasonEvent::class)->orderBy('position');
    }
}
