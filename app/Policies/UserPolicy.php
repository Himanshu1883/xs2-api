<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin() && $this->sameProviderStore($model);
    }

    public function changePassword(User $user, User $model): bool
    {
        return $user->isAdmin() && $this->sameProviderStore($model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && $this->sameProviderStore($model);
    }

    private function sameProviderStore(User $model): bool
    {
        return $model->store_id === (int) config('provider-auth.store_id');
    }
}
