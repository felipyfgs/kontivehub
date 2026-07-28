<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\FiscalDownloadData;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\Fiscal\SimplesMei\CcmeiCertificateIssuanceService;
use Throwable;

final readonly class ReadCcmeiCertificateAction
{
    public function __construct(
        private CcmeiCertificateIssuanceService $issuance,
    ) {}

    public function handle(
        Tenant $tenant,
        Client $client,
        int $certificateId,
    ): ?FiscalDownloadData {
        $certificate = $this->issuance->findForDownload(
            $tenant,
            $client,
            $certificateId,
        );
        if ($certificate === null) {
            return null;
        }

        try {
            $bytes = $this->issuance->read($certificate);
        } catch (Throwable) {
            return null;
        }

        return new FiscalDownloadData(
            bytes: $bytes,
            contentType: 'application/pdf',
            filename: 'ccmei-certificado-'.$certificate->id.'.pdf',
        );
    }
}
