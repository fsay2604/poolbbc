<?php

declare(strict_types=1);

namespace App\Enums;

enum Occupation: string
{
    case Actor = 'Actor';
    case Animator = 'Animator';
    case Athlete = 'Athlete';
    case Author = 'Author';
    case Chef = 'Chef';
    case Comedian = 'Comedian';
    case Dancer = 'Dancer';
    case Drag = 'Drag';
    case Gamer = 'Gamer';
    case Influencer = 'Influencer';
    case Model = 'Model';
    case Musician = 'Musician';
    case Singer = 'Singer';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $occupation) => $occupation->value, self::cases());
    }
}
