<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\ClientCategory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

class ClientCategoryPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientCategoriesManage);
    }

    public function update(User $user, ClientCategory $category): bool
    {
        return $this->allows($user, TenantPermission::ClientCategoriesManage, $category);
    }
}
