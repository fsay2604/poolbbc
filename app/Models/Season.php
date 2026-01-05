<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    /** @use HasFactory<\Database\Factories\SeasonFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'starts_on',
        'ends_on',
        'winner_houseguest_id',
        'first_evicted_houseguest_id',
        'top_6_houseguest_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'top_6_houseguest_ids' => 'array',
        ];
    }

    public function weeks(): HasMany
    {
        return $this->hasMany(Week::class);
    }

    public function houseguests(): HasMany
    {
        return $this->hasMany(Houseguest::class);
    }
}
