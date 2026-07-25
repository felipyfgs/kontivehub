<?php

namespace Tests\Unit\Enums;

use App\Enums\FiscalSourceProvenance;
use PHPUnit\Framework\TestCase;

class FiscalSourceProvenanceTest extends TestCase
{
    public function test_receita_portal_is_live_and_distinct_from_serpro(): void
    {
        $portal = FiscalSourceProvenance::ReceitaPortal;

        self::assertTrue($portal->isVerifiableCurrent());
        self::assertTrue($portal->isOfficialFiscalState());
        self::assertNotSame(FiscalSourceProvenance::SerproReal->value, $portal->value);
    }
}
