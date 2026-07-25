<?php

namespace Tests\Unit\Enums;

use App\Enums\SecureObjectPurpose;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecureObjectPurposeTest extends TestCase
{
    public function test_aad_base_preserves_typed_purpose_and_extra_metadata(): void
    {
        self::assertSame([
            'purpose' => 'FISCAL_EVIDENCE',
            'office_id' => 41,
            'sha256' => 'evidence-digest',
        ], SecureObjectPurpose::FiscalEvidence->aadBase([
            'office_id' => 41,
            'sha256' => 'evidence-digest',
        ]));
    }

    public function test_aad_base_rejects_an_attempt_to_override_purpose(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secure_object_purpose_reserved');

        SecureObjectPurpose::FiscalEvidence->aadBase([
            'purpose' => SecureObjectPurpose::SerproOauthSecrets->value,
            'office_id' => 41,
        ]);
    }
}
