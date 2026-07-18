<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LegacyShadowObservation extends Model
{
    /** @use HasFactory<\Database\Factories\LegacyShadowObservationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'authority_marker',
        'environment',
        'unapproved_differences',
        'report_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (LegacyShadowObservation $observation): void {
            $observation->observed_at = now();
            $observation->created_at = now();
        });

        static::updating(function (): never {
            throw new LogicException('Legacy shadow observations are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Legacy shadow observations cannot be removed.');
        });
    }

    protected function casts(): array
    {
        return [
            'unapproved_differences' => 'integer',
            'observed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(CanonicalFlowAuthority::class, 'authority_marker', 'marker');
    }
}
