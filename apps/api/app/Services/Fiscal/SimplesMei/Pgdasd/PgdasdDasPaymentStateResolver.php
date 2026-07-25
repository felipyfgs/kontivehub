<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdDasPaymentState;
use App\Models\PgdasdOperation;
use Carbon\CarbonImmutable;

/**
 * Resolve pagamento dos DAS do PA esperado a partir de evidência local.
 */
final class PgdasdDasPaymentStateResolver
{
    /**
     * @param  iterable<int, PgdasdOperation>  $dasForExpectedPa
     * @return array{
     *   state: PgdasdDasPaymentState,
     *   reason: string,
     *   das_count: int,
     *   unpaid_count: int,
     *   paid_count: int
     * }
     */
    public function resolve(iterable $dasForExpectedPa, bool $hasProductiveConsult): array
    {
        $ops = [];
        foreach ($dasForExpectedPa as $op) {
            if ($op instanceof PgdasdOperation) {
                $ops[] = $op;
            }
        }

        $dasCount = count($ops);
        $paid = 0;
        $unpaid = 0;
        $ttl = max(60, (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds',
            86_400,
        ));
        $freshAfter = CarbonImmutable::now()->subSeconds($ttl);
        foreach ($ops as $op) {
            if ($op->pagtoweb_payment_status === 'PAID') {
                $paid++;
            } elseif ($op->pagtoweb_payment_status === 'NOT_FOUND'
                && $op->pagtoweb_verified_at !== null
                && $op->pagtoweb_verified_at->greaterThanOrEqualTo($freshAfter)
            ) {
                $unpaid++;
            }
        }

        if ($dasCount === 0) {
            return $hasProductiveConsult
                ? $this->pack(PgdasdDasPaymentState::NoDas, 'NO_DAS_FOR_EXPECTED_PA', 0, 0, 0)
                : $this->pack(PgdasdDasPaymentState::Unverified, 'QUERY_NOT_VALID', 0, 0, 0);
        }

        if ($paid > 0) {
            return $this->pack(PgdasdDasPaymentState::Paid, 'PAGTOWEB_PAYMENT_LOCATED', $dasCount, $unpaid, $paid);
        }

        if ($unpaid === $dasCount) {
            return $this->pack(PgdasdDasPaymentState::Unpaid, 'PAGTOWEB_NOT_FOUND_COMPLETE', $dasCount, $unpaid, 0);
        }

        return $this->pack(PgdasdDasPaymentState::Unverified, 'PAGTOWEB_COVERAGE_INCOMPLETE', $dasCount, $unpaid, 0);
    }

    /**
     * @return array{
     *   state: PgdasdDasPaymentState,
     *   reason: string,
     *   das_count: int,
     *   unpaid_count: int,
     *   paid_count: int
     * }
     */
    private function pack(
        PgdasdDasPaymentState $state,
        string $reason,
        int $dasCount,
        int $unpaid,
        int $paid,
    ): array {
        return [
            'state' => $state,
            'reason' => $reason,
            'das_count' => $dasCount,
            'unpaid_count' => $unpaid,
            'paid_count' => $paid,
        ];
    }
}
