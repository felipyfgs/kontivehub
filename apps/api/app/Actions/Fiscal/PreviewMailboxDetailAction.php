<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\MailboxDetailPreviewData;
use App\Models\Tenant;
use App\Services\Integra\Mailbox\MailboxCostPolicy;
use App\Services\Integra\Mailbox\MailboxQueryService;

final readonly class PreviewMailboxDetailAction
{
    public function __construct(
        private MailboxQueryService $queries,
        private MailboxCostPolicy $cost,
    ) {}

    public function handle(
        Tenant $tenant,
        int $messageId,
    ): ?MailboxDetailPreviewData {
        $message = $this->queries->message($tenant, $messageId);
        if ($message === null) {
            return null;
        }

        return new MailboxDetailPreviewData(
            hasBody: (bool) $message->has_body,
            cost: $this->cost->preview((int) $tenant->id, 'DETALHE'),
        );
    }
}
