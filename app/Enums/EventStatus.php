<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Locked = 'locked';
    case ResultEntered = 'result_entered';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Event status draft',
            self::Open => 'Event status open',
            self::Locked => 'Event status locked',
            self::ResultEntered => 'Event status result entered',
            self::Published => 'Event status published',
            self::Cancelled => 'Event status cancelled',
        });
    }
}
