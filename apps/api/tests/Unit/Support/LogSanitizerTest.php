<?php

namespace Tests\Unit\Support;

use App\Support\LogSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    #[DataProvider('fiscalIdentifiers')]
    public function test_detects_numeric_alphanumeric_and_masked_fiscal_identifiers(string $value): void
    {
        self::assertTrue(LogSanitizer::looksLikeFiscalIdentifier($value));
    }

    /** @return iterable<string, array{string}> */
    public static function fiscalIdentifiers(): iterable
    {
        yield 'CNPJ numérico' => ['11222333000181'];
        yield 'CNPJ alfanumérico' => ['ABCDEF12000195'];
        yield 'CNPJ alfanumérico minúsculo e mascarado' => ['ab.cdef.12/0001-95'];
        yield 'chave de acesso' => [str_repeat('1', 44)];
    }

    public function test_metric_labels_drop_alphanumeric_cnpj_and_keep_regular_catalog_values(): void
    {
        self::assertSame([
            'status' => 'SUCCESS',
            'operation_code' => 'PGDASD_MONITOR',
        ], LogSanitizer::metricLabels([
            'service_code' => 'ABCDEF12000195',
            'status' => 'SUCCESS',
            'operation_code' => 'PGDASD_MONITOR',
        ]));
    }

    public function test_non_identifiers_do_not_match_the_closed_shapes(): void
    {
        self::assertFalse(LogSanitizer::looksLikeFiscalIdentifier('PGDASD_MONITOR'));
        self::assertFalse(LogSanitizer::looksLikeFiscalIdentifier('ABCDEF1200019'));
        self::assertFalse(LogSanitizer::looksLikeFiscalIdentifier('ABCDEF120001XX'));
    }
}
