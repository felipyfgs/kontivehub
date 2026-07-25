<?php

namespace Tests\Unit\Fiscal\Mutations;

use App\Services\Fiscal\Mutations\FiscalMutationCohort;
use Tests\TestCase;

final class FiscalMutationCohortTest extends TestCase
{
    public function test_it_reads_literal_dotted_operation_key_from_config(): void
    {
        config([
            'fiscal_mutations.enabled' => true,
            'fiscal_mutations.kill_switch' => false,
            'fiscal_mutations.operations' => [
                'INTEGRA_MEI.PGMEI.GERAR_DAS' => [
                    'enabled' => true,
                    'office_allowlist' => [17],
                    'allow_all_offices' => false,
                ],
            ],
        ]);

        self::assertTrue(FiscalMutationCohort::isOperationEnabled(
            'INTEGRA_MEI',
            'PGMEI',
            'GERAR_DAS',
            17,
        ));
        self::assertFalse(FiscalMutationCohort::isOperationEnabled(
            'INTEGRA_MEI',
            'PGMEI',
            'GERAR_DAS',
            18,
        ));
    }

    public function test_it_remains_fail_closed_for_unknown_operation(): void
    {
        config([
            'fiscal_mutations.enabled' => true,
            'fiscal_mutations.kill_switch' => false,
            'fiscal_mutations.operations' => [],
        ]);

        self::assertFalse(FiscalMutationCohort::isOperationEnabled(
            'INTEGRA_MEI',
            'PGMEI',
            'UNKNOWN',
            17,
        ));
    }
}
