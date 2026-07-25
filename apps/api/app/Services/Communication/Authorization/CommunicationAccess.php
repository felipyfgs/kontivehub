<?php

namespace App\Services\Communication\Authorization;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CommunicationAccess
{
    public function __construct(
        private CurrentOffice $currentOffice,
        private TenantAuthorization $authorization,
    ) {}

    public function assertView(User $actor, ?CommunicationInbox $inbox = null): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationView, $inbox)) {
            throw new AuthorizationException;
        }
        if ($inbox !== null && ! $this->canAccessInbox($actor, $inbox)) {
            throw new AuthorizationException;
        }
    }

    public function assertReply(User $actor, CommunicationInbox $inbox): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationReply, $inbox)
            || ! $this->canAccessInbox($actor, $inbox)) {
            throw new AuthorizationException;
        }
    }

    public function assertManage(User $actor, mixed $target = null): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationManageInboxes, $target)) {
            throw new AuthorizationException;
        }
    }

    public function assertManageContacts(User $actor, mixed $target = null): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationManageContacts, $target)) {
            throw new AuthorizationException;
        }
    }

    public function assertManageQuickReplies(User $actor, mixed $target = null): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationManageQuickReplies, $target)) {
            throw new AuthorizationException;
        }
    }

    public function assertManageFlows(User $actor, mixed $target = null): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationManageFlows, $target)) {
            throw new AuthorizationException;
        }
    }

    /** Leitura administrativa de fluxos: view ou manage_flows. */
    public function assertViewFlows(User $actor, mixed $target = null): void
    {
        $canView = $this->authorization->allows($actor, TenantPermission::CommunicationView, $target);
        $canManage = $this->authorization->allows($actor, TenantPermission::CommunicationManageFlows, $target);
        if (! $canView && ! $canManage) {
            throw new AuthorizationException;
        }
    }

    /** @return list<int> */
    public function visibleInboxIds(User $actor): array
    {
        $office = $this->currentOffice->resolve($actor);
        if ($office === null
            || ! $this->authorization->allows($actor, TenantPermission::CommunicationView)) {
            return [];
        }
        if ($this->currentOffice->role() === OfficeRole::Admin || $this->currentOffice->isPlatformPrivileged()) {
            return CommunicationInbox::query()->withoutGlobalScope('office')
                ->where('office_id', $office->id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }
        $membership = $this->currentOffice->realMembership();
        if ($membership === null) {
            return [];
        }

        return CommunicationInbox::query()->withoutGlobalScope('office')
            ->where('office_id', $office->id)
            ->whereHas('members', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('office_membership_id', $membership->id)
                ->where('is_active', true))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Auth de canal privado alinhada à visibilidade REST (Admin / platform privileged / member). */
    public function canAuthorizeInboxBroadcast(User $actor, int $inboxId): bool
    {
        $inbox = CommunicationInbox::query()->withoutGlobalScope('office')->find($inboxId);
        if ($inbox === null) {
            return false;
        }
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationView, $inbox)) {
            return false;
        }

        return $this->canAccessInbox($actor, $inbox);
    }

    /** Canal de office: manage + Office ativo correspondente (Admin ou platform privileged). */
    public function canAuthorizeOfficeBroadcast(User $actor, int $officeId): bool
    {
        if (! $this->authorization->allows($actor, TenantPermission::CommunicationManageInboxes)) {
            return false;
        }
        $office = $this->currentOffice->resolve($actor);
        if ($office === null || (int) $office->id !== $officeId) {
            return false;
        }

        return $this->currentOffice->role() === OfficeRole::Admin
            || $this->currentOffice->isPlatformPrivileged();
    }

    private function canAccessInbox(User $actor, CommunicationInbox $inbox): bool
    {
        $office = $this->currentOffice->resolve($actor);
        if ($office === null || (int) $inbox->office_id !== (int) $office->id) {
            return false;
        }
        if ($this->currentOffice->role() === OfficeRole::Admin || $this->currentOffice->isPlatformPrivileged()) {
            return true;
        }
        $membership = $this->currentOffice->realMembership();

        return $membership !== null && $inbox->members()
            ->withoutGlobalScopes()
            ->where('office_membership_id', $membership->id)
            ->where('is_active', true)
            ->exists();
    }
}
