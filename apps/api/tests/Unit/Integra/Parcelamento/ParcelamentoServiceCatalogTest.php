<?php

namespace Tests\Unit\Integra\Parcelamento;

use App\Enums\TaxInstallmentModality;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ParcelamentoServiceCatalogTest extends TestCase
{
    public function test_exposes_all_production_and_prospection_modalities_honestly(): void
    {
        $catalog = collect(ParcelamentoServiceCatalog::modalities())->keyBy('code');

        $this->assertCount(10, $catalog);
        foreach (TaxInstallmentModality::all() as $modality) {
            $this->assertTrue($catalog[$modality->value]['monitoring_supported']);
            $this->assertTrue($catalog[$modality->value]['executable']);
            $this->assertSame('PRODUCTION', $catalog[$modality->value]['official_state']);
        }
        foreach (['PARC-PAEX', 'PARC-SIPADE'] as $code) {
            $this->assertFalse($catalog[$code]['monitoring_supported']);
            $this->assertFalse($catalog[$code]['executable']);
            $this->assertSame('PROSPECTION', $catalog[$code]['official_state']);
            $this->assertTrue(ParcelamentoServiceCatalog::isKnownModality($code));
            $this->assertFalse(ParcelamentoServiceCatalog::isExecutableModality($code));
        }
    }

    public function test_resolves_operation_keys_for_every_production_modality(): void
    {
        foreach (TaxInstallmentModality::all() as $modality) {
            $prefix = strtolower(str_replace('-', '_', $modality->value));
            $this->assertSame("{$prefix}.pedidosparc", ParcelamentoServiceCatalog::operationKey($modality, 'MONITOR'));
            $this->assertSame("{$prefix}.obterparc", ParcelamentoServiceCatalog::operationKey($modality, 'CONSULTAR_PARCELAMENTO'));
            $this->assertSame("{$prefix}.parcelasparagerar", ParcelamentoServiceCatalog::operationKey($modality, 'CONSULTAR_PARCELAS'));
            $this->assertSame("{$prefix}.detpagtoparc", ParcelamentoServiceCatalog::operationKey($modality, 'CONSULTAR_PAGAMENTO'));
            $this->assertSame("{$prefix}.gerardas", ParcelamentoServiceCatalog::operationKey($modality, 'EMITIR_DOCUMENTO'));
        }
    }

    public function test_unknown_operation_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ParcelamentoServiceCatalog::operationKey(TaxInstallmentModality::Parcsn, 'ADERIR');
    }
}
