<?php

namespace Tests\Unit\Support;

use App\Support\LogSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    #[DataProvider('sensitiveTextPairs')]
    public function test_scrubs_sensitive_pairs_from_free_text(string $message, string $expected): void
    {
        self::assertSame($expected, LogSanitizer::scrubString($message));
    }

    /** @return iterable<string, array{string, string}> */
    public static function sensitiveTextPairs(): iterable
    {
        yield 'token curto' => [
            'Falha token=abc ao autenticar',
            'Falha token=[redacted] ao autenticar',
        ];
        yield 'secret com dois-pontos' => [
            'Provider secret: xyz indisponível',
            'Provider secret: [redacted] indisponível',
        ];
        yield 'password entre aspas' => [
            'password="valor curto" rejeitada',
            'password="[redacted]" rejeitada',
        ];
        yield 'authorization basic' => [
            'Authorization: Basic YWJjOmRlZg==',
            'Authorization: [redacted]',
        ];
        yield 'cookie' => [
            'Cookie=session=abc;tenant=42',
            'Cookie=[redacted]',
        ];
        yield 'cookie com espaços' => [
            'Cookie: session=abc; csrf_token=def',
            'Cookie: [redacted]',
        ];
        yield 'authorization digest' => [
            'Authorization: Digest username="alice", response="secret-digest"',
            'Authorization: [redacted]',
        ];
        yield 'jwt em json' => [
            '{"jwt":"abc.def.ghi","status":"failed"}',
            '{"jwt":"[redacted]","status":"failed"}',
        ];
        yield 'access token' => [
            'access_token: short-token',
            'access_token: [redacted]',
        ];
        yield 'password composta' => [
            'pfx_password=abc',
            'pfx_password=[redacted]',
        ];
        yield 'consumer secret' => [
            'consumer_secret=abc',
            'consumer_secret=[redacted]',
        ];
        yield 'activation token' => [
            'activation_token=abc',
            'activation_token=[redacted]',
        ];
        yield 'valor com aspas escapadas' => [
            'password="ab\\"cd"',
            'password="[redacted]"',
        ];
        yield 'metadata pública' => [
            'token_expires_at=2026-07-28T12:00:00Z',
            'token_expires_at=2026-07-28T12:00:00Z',
        ];
    }

    #[DataProvider('embeddedFiscalIdentifiers')]
    public function test_scrubs_embedded_fiscal_identifiers(string $identifier): void
    {
        $sanitized = LogSanitizer::scrubString("Falha ao consultar documento {$identifier} no provider");

        self::assertSame('Falha ao consultar documento [redacted] no provider', $sanitized);
    }

    /** @return iterable<string, array{string}> */
    public static function embeddedFiscalIdentifiers(): iterable
    {
        yield 'CNPJ numérico' => ['11222333000181'];
        yield 'CNPJ numérico mascarado' => ['11.222.333/0001-81'];
        yield 'CNPJ alfanumérico' => ['ABCDEF12000195'];
        yield 'CNPJ alfanumérico mascarado' => ['AB.CDE.F12/0001-95'];
        yield 'chave de acesso' => [str_repeat('1', 44)];
    }

    public function test_scrubs_before_truncating_free_text(): void
    {
        $sanitized = LogSanitizer::scrubString(str_repeat('a', 480).' token=abc '.str_repeat('b', 80));

        self::assertStringNotContainsString('abc', $sanitized);
        self::assertLessThanOrEqual(500, mb_strlen($sanitized));
    }

    public function test_preserves_regular_operational_text(): void
    {
        self::assertSame(
            'Provider SERPRO indisponível para PGDASD_MONITOR.',
            LogSanitizer::scrubString('  Provider  SERPRO indisponível para PGDASD_MONITOR.  '),
        );
    }

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

    public function test_redacts_cnpj_keys_in_structured_context(): void
    {
        $redacted = LogSanitizer::redact([
            'holder_cnpj' => '11222333000181',
            'cnpj' => 'ABCDEF12000195',
            'status' => 'SUCCESS',
            'access_key' => str_repeat('1', 44),
        ]);

        self::assertSame('[redacted]', $redacted['holder_cnpj']);
        self::assertSame('[redacted]', $redacted['cnpj']);
        self::assertSame('[redacted]', $redacted['access_key']);
        self::assertSame('SUCCESS', $redacted['status']);
    }

    public function test_non_identifiers_do_not_match_the_closed_shapes(): void
    {
        self::assertFalse(LogSanitizer::looksLikeFiscalIdentifier('PGDASD_MONITOR'));
        self::assertFalse(LogSanitizer::looksLikeFiscalIdentifier('ABCDEF1200019'));
        self::assertFalse(LogSanitizer::looksLikeFiscalIdentifier('ABCDEF120001XX'));
    }
}
