<?php

namespace App\Services\Integra\Mailbox;

use App\Models\MailboxAlert;
use App\Models\MailboxAttachment;
use App\Models\MailboxContributorState;
use App\Models\MailboxMessage;
use App\Models\Office;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class MailboxQueryService
{
    /**
     * @return LengthAwarePaginator<int, MailboxMessage>
     */
    public function messages(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $triageStatus = null,
    ): LengthAwarePaginator {
        $q = MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
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

    public function message(Office $office, int $messageId): ?MailboxMessage
    {
        return MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($messageId)
            ->with('attachments')
            ->first();
    }

    public function attachment(Office $office, int $messageId, int $attachmentId): ?MailboxAttachment
    {
        return MailboxAttachment::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('mailbox_message_id', $messageId)
            ->whereKey($attachmentId)
            ->first();
    }

    public function state(Office $office, int $clientId): ?MailboxContributorState
    {
        return MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $clientId)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, MailboxAlert>
     */
    public function alerts(Office $office, int $perPage = 50, bool $activeOnly = true): LengthAwarePaginator
    {
        $q = MailboxAlert::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('id');

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->paginate($perPage);
    }
}
