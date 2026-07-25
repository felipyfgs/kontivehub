<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\Enums\PgdasdDasPaymentState;
use App\Enums\PgdasdOperationKind;
use App\Models\PgdasdOperation;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdDasPaymentStateResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PgdasdDasPaymentStateResolverTest extends TestCase
{
    private PgdasdDasPaymentStateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-21 10:00:00');
        config()->set('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds', 86_400);
        $this->resolver = new PgdasdDasPaymentStateResolver;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_any_pagtoweb_paid_match_wins_over_negative_siblings(): void
    {
        $pack = $this->resolver->resolve([
            $this->das('PAID', now()),
            $this->das('NOT_FOUND', now()),
        ], true);

        $this->assertSame(PgdasdDasPaymentState::Paid, $pack['state']);
        $this->assertSame(2, $pack['das_count']);
        $this->assertSame(1, $pack['unpaid_count']);
        $this->assertSame(1, $pack['paid_count']);
        $this->assertSame('PAGTOWEB_PAYMENT_LOCATED', $pack['reason']);
    }

    public function test_unpaid_only_when_all_das_have_fresh_negative_coverage(): void
    {
        $pack = $this->resolver->resolve([
            $this->das('NOT_FOUND', now()),
            $this->das('NOT_FOUND', now()->subHour()),
        ], true);

        $this->assertSame(PgdasdDasPaymentState::Unpaid, $pack['state']);
        $this->assertSame('PAGTOWEB_NOT_FOUND_COMPLETE', $pack['reason']);
    }

    public function test_partial_coverage_is_unverified(): void
    {
        $pack = $this->resolver->resolve([
            $this->das('NOT_FOUND', now()),
            $this->das(null, null),
        ], true);

        $this->assertSame(PgdasdDasPaymentState::Unverified, $pack['state']);
        $this->assertSame('PAGTOWEB_COVERAGE_INCOMPLETE', $pack['reason']);
    }

    public function test_expired_negative_coverage_is_unverified(): void
    {
        $pack = $this->resolver->resolve([
            $this->das('NOT_FOUND', now()->subDay()->subSecond()),
        ], true);

        $this->assertSame(PgdasdDasPaymentState::Unverified, $pack['state']);
    }

    public function test_no_das_after_productive_consult(): void
    {
        $pack = $this->resolver->resolve([], true);

        $this->assertSame(PgdasdDasPaymentState::NoDas, $pack['state']);
        $this->assertSame('NO_DAS_FOR_EXPECTED_PA', $pack['reason']);
    }

    public function test_unverified_without_consult_and_without_das(): void
    {
        $pack = $this->resolver->resolve([], false);

        $this->assertSame(PgdasdDasPaymentState::Unverified, $pack['state']);
    }

    public function test_das_with_unknown_payment(): void
    {
        $pack = $this->resolver->resolve([
            $this->das(null, null),
        ], true);

        $this->assertSame(PgdasdDasPaymentState::Unverified, $pack['state']);
        $this->assertSame('PAGTOWEB_COVERAGE_INCOMPLETE', $pack['reason']);
    }

    public function test_legacy_das_pago_does_not_decide_payment_state_without_pagtoweb_auth(): void
    {
        $pack = $this->resolver->resolve([
            $this->das(null, null, true),
        ], true);

        $this->assertSame(PgdasdDasPaymentState::Unverified, $pack['state']);
    }

    #[DataProvider('labelProvider')]
    public function test_labels(PgdasdDasPaymentState $state, string $label): void
    {
        $this->assertSame($label, $state->label());
    }

    /**
     * @return list<array{0: PgdasdDasPaymentState, 1: string}>
     */
    public static function labelProvider(): array
    {
        return [
            [PgdasdDasPaymentState::Paid, 'Em dia'],
            [PgdasdDasPaymentState::Unpaid, 'Pendências'],
            [PgdasdDasPaymentState::NoDas, 'Sem DAS'],
            [PgdasdDasPaymentState::Unverified, 'Não verificado'],
        ];
    }

    private function das(
        ?string $pagtowebStatus,
        mixed $verifiedAt,
        ?bool $paymentLocated = null,
    ): PgdasdOperation {
        $op = new PgdasdOperation;
        $op->forceFill([
            'kind' => PgdasdOperationKind::Das,
            'pagtoweb_payment_status' => $pagtowebStatus,
            'pagtoweb_verified_at' => $verifiedAt,
            'payment_located' => $paymentLocated,
        ]);

        return $op;
    }
}
