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
}
