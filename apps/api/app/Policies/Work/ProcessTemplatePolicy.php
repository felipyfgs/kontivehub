<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\ProcessTemplate;
use App\Models\User;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class ProcessTemplatePolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView);
    }

    public function view(User $user, ProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $template);
    }

    public function create(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage);
    }

    public function update(User $user, ProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage, $template);
    }

    public function generate(User $user, ProcessTemplate $template): bool
    {
        return $template->is_active
            && $this->allowsWork($user, TenantPermission::WorkProcessesCreate, $template);
    }

    public function manageRecurrence(User $user, ProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage, $template);
    }

    public function viewGenerations(User $user, ProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $template);
    }

    public function retryGeneration(User $user, ProcessTemplate $template): bool
    {
        return $template->is_active
            && $this->allowsWork($user, TenantPermission::WorkProcessesCreate, $template);
    }
}
