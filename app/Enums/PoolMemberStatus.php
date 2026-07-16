<?php

namespace App\Enums;

enum PoolMemberStatus: string
{
    case Active = 'active';
    case Removed = 'removed';
}
