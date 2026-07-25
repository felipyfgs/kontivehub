<?php

namespace Tests\Unit\Models;

use App\Enums\FgtsDigitalRepresentationStatus;
use App\Models\FgtsDigitalRepresentation;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FgtsDigitalRepresentationTest extends TestCase
{
    #[DataProvider('validityWindows')]
    public function test_usability_respects_status_and_the_complete_validity_window(
        FgtsDigitalRepresentationStatus $status,
        ?int $validFromOffsetSeconds,
        ?int $validToOffsetSeconds,
        bool $expected,
    ): void {
        $at = CarbonImmutable::parse('2026-07-22T12:00:00-03:00');
        $storedAt = $at->utc();
        $representation = (new FgtsDigitalRepresentation)->forceFill([
            'status' => $status,
            'valid_from' => $validFromOffsetSeconds === null ? null : $storedAt->addSeconds($validFromOffsetSeconds),
            'valid_to' => $validToOffsetSeconds === null ? null : $storedAt->addSeconds($validToOffsetSeconds),
        ]);

        self::assertSame($expected, $representation->isUsable($at));
    }

    /** @return iterable<string, array{FgtsDigitalRepresentationStatus, ?int, ?int, bool}> */
    public static function validityWindows(): iterable
    {
        yield 'vigente' => [
            FgtsDigitalRepresentationStatus::Active,
            -1,
            1,
            true,
        ];
        yield 'início inclusivo' => [
            FgtsDigitalRepresentationStatus::Active,
            0,
            null,
            true,
        ];
        yield 'ainda não vigente' => [
            FgtsDigitalRepresentationStatus::Active,
            1,
            null,
            false,
        ];
        yield 'fim exclusivo' => [
            FgtsDigitalRepresentationStatus::Active,
            null,
            0,
            false,
        ];
        yield 'status inativo' => [
            FgtsDigitalRepresentationStatus::Pending,
            null,
            null,
            false,
        ];
    }
}
