<?php

namespace App\Enums;

enum PoolStatus: string
{
    case Configuration = 'configuration';
    case Registration = 'registration';
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return __(match ($this) {
            self::Configuration => 'Pool status configuration',
            self::Registration => 'Pool status registration',
            self::Draft => 'Pool status draft',
            self::Active => 'Pool status active',
            self::Completed => 'Pool status completed',
            self::Archived => 'Pool status archived',
        });
    }
}
