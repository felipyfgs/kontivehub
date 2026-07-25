<?php

namespace Tests\Unit\Domain\Outbound;

use App\Domain\Outbound\Competence;
use PHPUnit\Framework\TestCase;

final class CompetenceTest extends TestCase
{
    public function test_extracts_competence_only_from_a_complete_access_key(): void
    {
        $accessKey = '352607'.str_repeat('A', 38);

        self::assertSame('2026-07', Competence::tryFromAccessKey($accessKey)?->value());
        self::assertNull(Competence::tryFromAccessKey('352607'));
        self::assertNull(Competence::tryFromAccessKey($accessKey.'A'));
        self::assertNull(Competence::tryFromAccessKey(substr_replace($accessKey, '-', 10, 1)));
        self::assertNull(Competence::tryFromAccessKey(substr_replace($accessKey, ' ', 10, 0)));
        self::assertNull(Competence::tryFromAccessKey(strtolower($accessKey)));
    }

    public function test_rejects_invalid_month_inside_a_complete_key(): void
    {
        self::assertNull(Competence::tryFromAccessKey('352613'.str_repeat('A', 38)));
    }
}
