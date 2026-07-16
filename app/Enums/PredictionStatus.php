<?php

namespace App\Enums;

enum PredictionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Locked = 'locked';
}
