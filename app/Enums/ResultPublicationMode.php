<?php

namespace App\Enums;

enum ResultPublicationMode: string
{
    case Immediate = 'immediate';
    case Manual = 'manual';

    public function label(): string
    {
        return __(match ($this) {
            self::Immediate => 'Immediate result publication',
            self::Manual => 'Manual result publication',
        });
    }
}
