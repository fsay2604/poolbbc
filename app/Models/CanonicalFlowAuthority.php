<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CanonicalFlowAuthority extends Model
{
    public const MARKER = 'canonical';

    public $incrementing = false;

    protected $primaryKey = 'marker';

    protected $keyType = 'string';

    protected $fillable = ['marker'];

    protected static function booted(): void
    {
        static::creating(function (CanonicalFlowAuthority $authority): void {
            if ($authority->marker !== self::MARKER) {
                throw new LogicException('Only the canonical flow authority marker may be created.');
            }

            $authority->activated_at = now();
        });

        static::updating(function (): never {
            throw new LogicException('The canonical flow authority marker is immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('The canonical flow authority marker cannot be removed.');
        });
    }

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }
}
