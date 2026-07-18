<?php

namespace App\Enums;

enum PoolMemberRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Member = 'member';

    public function label(): string
    {
        return __(match ($this) {
            self::Owner => 'Pool member role owner',
            self::Administrator => 'Pool member role administrator',
            self::Member => 'Pool member role member',
        });
    }
}
