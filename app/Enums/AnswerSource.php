<?php

namespace App\Enums;

enum AnswerSource: string
{
    case Houseguests = 'houseguests';
    case Custom = 'custom';
    case Boolean = 'boolean';
}
