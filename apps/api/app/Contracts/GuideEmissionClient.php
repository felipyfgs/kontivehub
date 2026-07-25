<?php

namespace App\Contracts;

use App\Services\Fiscal\Guides\DTO\GuideEmissionRequest;
use App\Services\Fiscal\Guides\DTO\GuideEmissionResult;
use App\Services\Fiscal\Guides\DTO\GuidePaymentLookupRequest;
use App\Services\Fiscal\Guides\DTO\GuidePaymentLookupResult;
use App\Services\Fiscal\Guides\DTO\GuideReconcileRequest;
use App\Services\Fiscal\Guides\DTO\GuideReconcileResult;

/**
 * Fonte oficial de emissão/reconciliação/pagamento de guias.
 * Implementações reais (SERPRO) virão em adapters por solução; mutações OFF por default.
 */
interface GuideEmissionClient
{
    public function emit(GuideEmissionRequest $request): GuideEmissionResult;

    public function reconcile(GuideReconcileRequest $request): GuideReconcileResult;

    public function lookupPayment(GuidePaymentLookupRequest $request): GuidePaymentLookupResult;
}
