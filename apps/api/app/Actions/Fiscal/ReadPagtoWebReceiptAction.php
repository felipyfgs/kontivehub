<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\FiscalDownloadData;
use App\Models\Client;
use App\Models\PagtoWebArrecadacaoReceipt;
use App\Models\Tenant;
use App\Services\Fiscal\Guides\PagtoWebArrecadacaoReceiptProjector;
use Throwable;

final readonly class ReadPagtoWebReceiptAction
{
    public function __construct(
        private PagtoWebArrecadacaoReceiptProjector $projector,
    ) {}

    public function handle(
        Tenant $tenant,
        Client $client,
        int $receiptId,
    ): ?FiscalDownloadData {
        $receipt = PagtoWebArrecadacaoReceipt::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->whereKey($receiptId)
            ->first();
        if ($receipt === null) {
            return null;
        }

        try {
            $bytes = $this->projector->readAuthorized(
                $receipt,
                (int) $tenant->id,
            );
        } catch (Throwable) {
            return null;
        }

        return new FiscalDownloadData(
            bytes: $bytes,
            contentType: 'application/pdf',
            filename: 'comprovante-pagtoweb-'.$receipt->id.'.pdf',
        );
    }
}
