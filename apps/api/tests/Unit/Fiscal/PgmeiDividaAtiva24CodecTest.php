<?php

namespace Tests\Unit\Fiscal;

use App\DTO\Serpro\IntegraResponse;
use App\Enums\PgmeiDebtState;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDividaAtiva24Codec;
use RuntimeException;
use Tests\TestCase;

class PgmeiDividaAtiva24CodecTest extends TestCase
{
    public function test_build_payload_formats_calendar_year(): void
    {
        $codec = new PgmeiDividaAtiva24Codec;

        $this->assertSame(['anoCalendario' => '2024'], $codec->buildPayload(2024));
        $this->assertSame(['anoCalendario' => '2024'], $codec->buildPayload('2024'));
    }

    public function test_decode_dados_empty_list_is_no_active_debt(): void
    {
        $decoded = (new PgmeiDividaAtiva24Codec)->decodeDados([], 2024);

        $this->assertSame(PgmeiDebtState::NoActiveDebt->value, $decoded['state']);
        $this->assertSame(0, $decoded['debt_count']);
        $this->assertSame([], $decoded['items']);
    }

    public function test_decode_response_with_null_dados_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DIVIDAATIVA24: response.dados ausente');

        (new PgmeiDividaAtiva24Codec)->decodeResponse(
            new IntegraResponse(
                success: true,
                httpStatus: 200,
                body: [],
                dados: null,
            ),
            2024,
        );
    }
}
