<?php

namespace App\Enums;

enum EventMode: string
{
    case Roster = 'roster';
    case Prediction = 'prediction';
    case Hybrid = 'hybrid';
}
