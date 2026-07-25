<?php

namespace Tests\Unit\Fiscal\Module;

use App\DTO\Fiscal\Module\ModuleClientRowDto;
use App\Enums\FiscalDataOrigin;
use App\Enums\FiscalModuleKey;
use Tests\TestCase;

class ModuleClientRowDtoCnpjTest extends TestCase
{
    public function test_to_array_exposes_normalized_cnpj_and_keeps_masked(): void
    {
        $dto = new ModuleClientRowDto(
            moduleKey: FiscalModuleKey::SimplesMei,
            clientId: 10,
            legalName: 'Cliente Teste',
            displayName: null,
            cnpj: '26461528000151',
            cnpjMasked: '2646******0151',
            rootCnpjMasked: '26461528',
            competence: null,
            situation: 'PENDING',
            coverage: 'UNKNOWN',
            dataOrigin: FiscalDataOrigin::Live,
            lastConsultedAt: null,
            nextDeadlineAt: null,
            nextAction: null,
        );

        $payload = $dto->toArray();

        $this->assertSame('26461528000151', $payload['cnpj']);
        $this->assertSame(14, strlen((string) $payload['cnpj']));
        $this->assertSame('2646******0151', $payload['cnpj_masked']);
    }
}
