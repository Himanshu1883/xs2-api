<?php

namespace App\Policies;

use App\Models\EventMapping;
use App\Models\User;

class EventMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, EventMapping $mapping): bool
    {
        return $user->isAdmin();
    }

    public function map(User $user, EventMapping $mapping): bool
    {
        return $user->isAdmin();
    }

    public function createEvent(User $user, EventMapping $mapping): bool
    {
        return $user->isAdmin();
    }

    public function ignore(User $user, EventMapping $mapping): bool
    {
        return $user->isAdmin();
    }

    public function reopen(User $user, EventMapping $mapping): bool
    {
        return $user->isAdmin();
    }

    public function recalculate(User $user, EventMapping $mapping): bool
    {
        return $user->isAdmin();
    }
}
