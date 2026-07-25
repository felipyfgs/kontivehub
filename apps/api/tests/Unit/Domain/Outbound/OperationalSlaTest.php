<?php

namespace Tests\Unit\Domain\Outbound;

use App\Domain\Outbound\OperationalSla;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationalSlaTest extends TestCase
{
    #[DataProvider('validTimes')]
    public function test_accepts_real_civil_times(string $dueTime): void
    {
        $sla = new OperationalSla('America/Sao_Paulo', 1, $dueTime, 24);

        self::assertSame($dueTime, $sla->dueTime);
    }

    /** @return iterable<string, array{string}> */
    public static function validTimes(): iterable
    {
        yield 'início do dia' => ['00:00:00'];
        yield 'fim do dia' => ['23:59:59'];
    }

    #[DataProvider('invalidTimes')]
    public function test_rejects_overflow_and_non_canonical_times(string $dueTime): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('due_time deve ser HH:MM:SS.');

        new OperationalSla('America/Sao_Paulo', 1, $dueTime, 24);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTimes(): iterable
    {
        yield 'hora 24' => ['24:00:00'];
        yield 'minuto 60' => ['23:60:00'];
        yield 'segundo 60' => ['23:59:60'];
        yield 'overflow completo' => ['99:99:99'];
        yield 'hora sem zero' => ['9:00:00'];
    }
}
