<?php

namespace App\Enums;

enum PoolCompetitionMode: string
{
    case PredictionOnly = 'prediction_only';
    case RosterOnly = 'roster_only';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return __(match ($this) {
            self::PredictionOnly => 'Pool competition mode prediction only',
            self::RosterOnly => 'Pool competition mode roster only',
            self::Hybrid => 'Pool competition mode hybrid',
        });
    }
}
