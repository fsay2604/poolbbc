<?php

namespace App\Enums;

enum OfficialRoundTemplate: string
{
    case Standard = 'standard';
    case NoVeto = 'no_veto';
    case DoubleEviction = 'double_eviction';
    case Finale = 'finale';
}
