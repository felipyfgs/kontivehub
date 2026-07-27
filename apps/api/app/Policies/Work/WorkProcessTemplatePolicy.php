<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class WorkProcessTemplatePolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView);
    }

    public function view(User $user, WorkProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $template);
    }

    public function create(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage);
    }

    public function update(User $user, WorkProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage, $template);
    }

    public function generate(User $user, WorkProcessTemplate $template): bool
    {
        return $template->is_active
            && $this->allowsWork($user, TenantPermission::WorkProcessesCreate, $template);
    }

    public function manageRecurrence(User $user, WorkProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage, $template);
    }

    public function viewGenerations(User $user, WorkProcessTemplate $template): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $template);
    }

    public function retryGeneration(User $user, WorkProcessTemplate $template): bool
    {
        return $template->is_active
            && $this->allowsWork($user, TenantPermission::WorkProcessesCreate, $template);
    }
}
