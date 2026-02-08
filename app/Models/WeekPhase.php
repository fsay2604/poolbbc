<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeekPhase extends Model
{
    /** @use HasFactory<\Database\Factories\WeekPhaseFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'week_id',
        'position',
        'type',
        'config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'config' => 'array',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(Week::class);
    }
}
