<?php

namespace App\Enums;

enum EventMode: string
{
    case Roster = 'roster';
    case Prediction = 'prediction';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return __(match ($this) {
            self::Roster => 'Event mode roster',
            self::Prediction => 'Event mode prediction',
            self::Hybrid => 'Event mode hybrid',
        });
    }
}
