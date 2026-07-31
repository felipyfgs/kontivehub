<?php

namespace App\Services\Communication\Authorization;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class Access
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAuthorization $authorization,
    ) {}

    public function assertView(User $actor, ?CommunicationInbox $inbox = null): void
    {
        if (! $this->canView($actor, $inbox)) {
            throw new AuthorizationException;
        }
    }

    public function canView(User $actor, ?CommunicationInbox $inbox = null): bool
    {
        return $this->authorization->allows($actor, TenantPermission::CommunicationView, $inbox)
            && ($inbox === null || $this->canAccessInbox($actor, $inbox));
    }

    public function assertReply(User $actor, CommunicationInbox $inbox): void
    {
        if (! $this->canReply($actor, $inbox)) {
            throw new AuthorizationException;
        }
    }

    public function canReply(User $actor, CommunicationInbox $inbox): bool
    {
        return $this->authorization->allows($actor, TenantPermission::CommunicationReply, $inbox)
            && $this->canAccessInbox($actor, $inbox);
    }

    public function assertManage(User $actor, mixed $target = null): void
    {
        if (! $this->canManage($actor, $target)) {
            throw new AuthorizationException;
        }
    }

    public function canManage(User $actor, mixed $target = null): bool
    {
        return $this->authorization->allows(
            $actor,
            TenantPermission::CommunicationManageInboxes,
            $target,
        );
    }

    public function assertManageContacts(User $actor, mixed $target = null): void
    {
        if (! $this->canManageContacts($actor, $target)) {
            throw new AuthorizationException;
        }
    }

    public function canManageContacts(User $actor, mixed $target = null): bool
    {
        return $this->authorization->allows(
            $actor,
            TenantPermission::CommunicationManageContacts,
            $target,
        );
    }

    public function assertManageQuickReplies(User $actor, mixed $target = null): void
    {
        if (! $this->canManageQuickReplies($actor, $target)) {
            throw new AuthorizationException;
        }
    }

    public function canManageQuickReplies(User $actor, mixed $target = null): bool
    {
        return $this->authorization->allows(
            $actor,
            TenantPermission::CommunicationManageQuickReplies,
            $target,
        );
    }

    public function assertManageFlows(User $actor, mixed $target = null): void
    {
        if (! $this->canManageFlows($actor, $target)) {
            throw new AuthorizationException;
        }
    }

    public function canManageFlows(User $actor, mixed $target = null): bool
    {
        return $this->authorization->allows(
            $actor,
            TenantPermission::CommunicationManageFlows,
            $target,
        );
    }

    /** Leitura administrativa de fluxos: view ou manage_flows. */
    public function assertViewFlows(User $actor, mixed $target = null): void
    {
        if (! $this->canViewFlows($actor, $target)) {
            throw new AuthorizationException;
        }
    }

    public function canViewFlows(User $actor, mixed $target = null): bool
    {
        return $this->authorization->allows(
            $actor,
            TenantPermission::CommunicationView,
            $target,
        ) || $this->authorization->allows(
            $actor,
            TenantPermission::CommunicationManageFlows,
            $target,
        );
    }

    /** @return list<int> */
    public function visibleInboxIds(User $actor): array
    {
        $tenant = $this->currentTenant->resolve($actor);
        if ($tenant === null
            || ! $this->authorization->allows($actor, TenantPermission::CommunicationView)) {
            return [];
        }
        if ($this->currentTenant->role() === TenantRole::TenantAdmin || $this->currentTenant->isPlatformPrivileged()) {
            return CommunicationInbox::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }
        $membership = $this->currentTenant->realMembership();
        if ($membership === null) {
            return [];
        }

        return CommunicationInbox::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereHas('members', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('tenant_membership_id', $membership->id)
                ->where('is_active', true))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Auth de canal privado alinhada à visibilidade REST (Admin / platform privileged / member). */
    public function canAuthorizeInboxBroadcast(User $actor, int $inboxId): bool
    {
        $inbox = CommunicationInbox::query()->withoutGlobalScope('tenant')->find($inboxId);
        if ($inbox === null) {
            return false;
        }
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationView, $inbox)) {
            return false;
        }

        return $this->canAccessInbox($actor, $inbox);
    }

    /** Canal de tenant: manage + Tenant ativo correspondente (Admin ou platform privileged). */
    public function canAuthorizeTenantBroadcast(User $actor, int $tenantId): bool
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationManageInboxes)) {
            return false;
        }
        $tenant = $this->currentTenant->resolve($actor);
        if ($tenant === null || (int) $tenant->id !== $tenantId) {
            return false;
        }

        return $this->currentTenant->role() === TenantRole::TenantAdmin
            || $this->currentTenant->isPlatformPrivileged();
    }

    private function canAccessInbox(User $actor, CommunicationInbox $inbox): bool
    {
        $tenant = $this->currentTenant->resolve($actor);
        if ($tenant === null || (int) $inbox->tenant_id !== (int) $tenant->id) {
            return false;
        }
        if ($this->currentTenant->role() === TenantRole::TenantAdmin || $this->currentTenant->isPlatformPrivileged()) {
            return true;
        }
        $membership = $this->currentTenant->realMembership();

        return $membership !== null && $inbox->members()
            ->withoutGlobalScopes()
            ->where('tenant_membership_id', $membership->id)
            ->where('is_active', true)
            ->exists();
    }
}
