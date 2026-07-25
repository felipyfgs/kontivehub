<?php

namespace Tests\Unit\Fiscal\Mutations;

use App\DTO\Serpro\MutationAuthorization;
use App\Enums\FiscalMutationStatus;
use App\Models\FiscalMutationOperation;
use App\Services\Fiscal\Mutations\FiscalMutationPayload;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class FiscalMutationPayloadTest extends TestCase
{
    public function test_digest_is_stable_for_associative_key_order_and_sensitive_to_values(): void
    {
        $first = FiscalMutationPayload::digest([
            'period' => '2026-07',
            'declaration' => ['revenue' => 100, 'activities' => ['A', 'B']],
        ]);
        $reordered = FiscalMutationPayload::digest([
            'declaration' => ['activities' => ['A', 'B'], 'revenue' => 100],
            'period' => '2026-07',
        ]);
        $changed = FiscalMutationPayload::digest([
            'declaration' => ['activities' => ['A', 'B'], 'revenue' => 101],
            'period' => '2026-07',
        ]);

        self::assertSame($first, $reordered);
        self::assertNotSame($first, $changed);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_mutation_authorization_is_fail_closed_and_bound_to_exact_operation(): void
    {
        $none = MutationAuthorization::none();
        self::assertTrue($none->allowsMutatingOperation('pgdasd.transdeclaracao', false));
        self::assertFalse($none->allowsMutatingOperation('pgdasd.transdeclaracao', true));

        $operation = new FiscalMutationOperation;
        $operation->forceFill([
            'id' => 91,
            'requested_by' => 7,
            'status' => FiscalMutationStatus::Sent,
            'confirmed_by_user' => true,
            'confirmed_at' => Carbon::parse('2026-07-21T12:00:00-03:00'),
            'eligibility_snapshot' => ['allowed' => true],
            'provider_operation_key' => 'pgdasd.transdeclaracao',
            'request_payload_digest' => str_repeat('a', 64),
        ]);

        $authorization = MutationAuthorization::fromPersistedOperation(
            $operation,
            'pgdasd.transdeclaracao',
        );

        self::assertTrue($authorization->allowsMutatingOperation('pgdasd.transdeclaracao', true));
        self::assertFalse($authorization->allowsMutatingOperation('defis.transdeclaracao', true));
        self::assertTrue($authorization->toSanitizedArray()['has_operation_binding']);
    }

    public function test_mutation_authorization_rejects_unconfirmed_or_payload_unbound_operation(): void
    {
        $operation = new FiscalMutationOperation;
        $operation->forceFill([
            'id' => 92,
            'requested_by' => 7,
            'status' => FiscalMutationStatus::Sent,
            'confirmed_by_user' => false,
            'confirmed_at' => Carbon::now(),
            'eligibility_snapshot' => ['allowed' => true],
            'provider_operation_key' => 'pgdasd.transdeclaracao',
            'request_payload_digest' => null,
        ]);

        $authorization = MutationAuthorization::fromPersistedOperation(
            $operation,
            'pgdasd.transdeclaracao',
        );

        self::assertFalse($authorization->approved);
        self::assertFalse($authorization->allowsMutatingOperation('pgdasd.transdeclaracao', true));
    }
}
