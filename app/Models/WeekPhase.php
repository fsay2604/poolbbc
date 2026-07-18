<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

    protected static function booted(): void
    {
        $rejectWhenFrozen = function (self $phase): void {
            if ($phase->week()->where(fn ($query) => $query
                ->whereHas('predictions')
                ->orWhereHas('outcome'))->exists()) {
                throw ValidationException::withMessages([
                    'phase' => __('Week phases cannot be changed after the first response.'),
                ]);
            }
        };

        static::creating($rejectWhenFrozen);
        static::updating($rejectWhenFrozen);
        static::deleting($rejectWhenFrozen);
    }
}
