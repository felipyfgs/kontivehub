<?php

namespace App\Services\Integra\Mailbox;

use App\Models\MailboxAlert;
use App\Models\MailboxAttachment;
use App\Models\MailboxContributorState;
use App\Models\MailboxMessage;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class MailboxQueryService
{
    /**
     * @return LengthAwarePaginator<int, MailboxMessage>
     */
    public function messages(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $triageStatus = null,
    ): LengthAwarePaginator {
        $q = MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('received_at_official')
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($triageStatus !== null && $triageStatus !== '') {
            $q->where('triage_status', strtoupper($triageStatus));
        }

        return $q->paginate($perPage);
    }

    public function message(Tenant $tenant, int $messageId): ?MailboxMessage
    {
        return MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($messageId)
            ->with('attachments')
            ->first();
    }

    public function attachment(Tenant $tenant, int $messageId, int $attachmentId): ?MailboxAttachment
    {
        return MailboxAttachment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('mailbox_message_id', $messageId)
            ->whereKey($attachmentId)
            ->first();
    }

    public function state(Tenant $tenant, int $clientId): ?MailboxContributorState
    {
        return MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $clientId)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, MailboxAlert>
     */
    public function alerts(Tenant $tenant, int $perPage = 50, bool $activeOnly = true): LengthAwarePaginator
    {
        $q = MailboxAlert::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->paginate($perPage);
    }
}
