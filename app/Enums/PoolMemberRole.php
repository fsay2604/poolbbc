<?php

namespace App\Enums;

enum PoolMemberRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Member = 'member';
}
