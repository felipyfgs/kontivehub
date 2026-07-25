<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use App\Enums\PgdasdDeclarationState;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PgdasdDeclarationStateTest extends TestCase
{
    #[DataProvider('mappingProvider')]
    public function test_maps_to_fiscal_situation_and_label(
        PgdasdDeclarationState $state,
        FiscalSituation $situation,
        string $label,
    ): void {
        $this->assertSame($situation, $state->toFiscalSituation());
        $this->assertSame($label, $state->label());
    }

    /**
     * @return list<array{0: PgdasdDeclarationState, 1: FiscalSituation, 2: string}>
     */
    public static function mappingProvider(): array
    {
        return [
            [PgdasdDeclarationState::Current, FiscalSituation::UpToDate, 'Em dia'],
            [PgdasdDeclarationState::DueWithinDeadline, FiscalSituation::Pending, 'No prazo'],
            [PgdasdDeclarationState::OverdueNotFound, FiscalSituation::Attention, 'Atrasado'],
            [PgdasdDeclarationState::Unverified, FiscalSituation::Unknown, 'Não verificado'],
        ];
    }
}
