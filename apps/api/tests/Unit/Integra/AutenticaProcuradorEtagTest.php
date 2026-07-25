<?php

namespace Tests\Unit\Integra;

use App\Services\Integra\AutenticaProcuradorEtag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AutenticaProcuradorEtagTest extends TestCase
{
    public function test_extracts_token_from_strong_and_weak_etag(): void
    {
        $this->assertSame(
            'token-opaco-123',
            AutenticaProcuradorEtag::extractToken('"autenticar_procurador_token:token-opaco-123"'),
        );
        $this->assertSame(
            'abc.DEF-_9',
            AutenticaProcuradorEtag::extractToken('W/"autenticar_procurador_token:abc.DEF-_9"'),
        );
    }

    #[DataProvider('invalidEtags')]
    public function test_rejects_non_official_or_injectable_etag(string $etag): void
    {
        $this->assertNull(AutenticaProcuradorEtag::extractToken($etag));

        $this->expectException(RuntimeException::class);
        AutenticaProcuradorEtag::assertValidCondition($etag);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEtags(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong prefix' => ['opaque-etag'];
        yield 'empty token' => ['autenticar_procurador_token:'];
        yield 'crlf injection' => ["autenticar_procurador_token:abc\r\nX-Evil: yes"];
        yield 'trailing control' => ["autenticar_procurador_token:abc\n"];
        yield 'token whitespace' => ['autenticar_procurador_token:abc def'];
    }
}
