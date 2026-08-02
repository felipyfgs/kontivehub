<?php

namespace App\Services\Communication\Contact;

use App\Enums\CommunicationChannel;
use App\Jobs\Communication\ReconcileInboxIdentityProfileJob;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;

/** Coalesces post-commit reconciliation without performing provider I/O inline. */
final readonly class InboxIdentityProfileReconciliationScheduler
{
    public function schedule(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
    ): void {
        if ((int) $identity->tenant_id !== (int) $inbox->tenant_id
            || $identity->channel !== CommunicationChannel::WhatsApp
            || ! $identity->is_active
            || $identity->purged_at !== null) {
            return;
        }

        ReconcileInboxIdentityProfileJob::dispatch(
            (int) $inbox->tenant_id,
            (int) $inbox->id,
            (int) $identity->id,
        )->afterCommit();
    }
}
