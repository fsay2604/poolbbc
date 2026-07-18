<?php

namespace App\Enums;

enum DraftStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return __(match ($this) {
            self::Pending => 'Draft status pending',
            self::Active => 'Draft status active',
            self::Paused => 'Draft status paused',
            self::Completed => 'Draft status completed',
        });
    }
}
