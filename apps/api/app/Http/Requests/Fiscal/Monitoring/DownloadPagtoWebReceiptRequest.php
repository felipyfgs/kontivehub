<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class DownloadPagtoWebReceiptRequest extends FiscalClientReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function receiptId(): int
    {
        return (int) $this->route('receipt');
    }
}
