<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    /** @use HasFactory<\Database\Factories\RoundFactory> */
    use HasFactory;

    protected $fillable = ['pool_id', 'name', 'position', 'status', 'starts_at', 'ends_at'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class)->orderBy('position');
    }
}
