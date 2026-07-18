<?php

namespace App\Policies;

use App\Models\EventResult;
use App\Models\User;

class EventResultPolicy
{
    public function amend(User $user, EventResult $result): bool
    {
        return $result->status === 'draft'
            && (new EventPolicy)->update($user, $result->event);
    }
}
