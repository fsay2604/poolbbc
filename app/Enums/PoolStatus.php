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
}
