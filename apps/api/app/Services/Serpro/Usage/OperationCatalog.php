<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproConsumptionClass;
use App\Models\SerproOperationCatalogEntry;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Classifica operações SERPRO por catálogo versionado (vigência).
 * Operação ausente → DESCONHECIDA (não inventar NAO_FATURAVEL/custo zero).
 */
final class OperationCatalog
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{class: SerproConsumptionClass, is_essential: bool, catalog_id: int|null, label: string|null}
     */
    public function classify(
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        Carbon|string|null $at = null,
    ): array {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());

        $entry = SerproOperationCatalogEntry::query()
            ->where('system_code', $systemCode)
            ->where('service_code', $serviceCode)
            ->where('operation_code', $operationCode)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($entry === null) {
            $this->alertUnknown($systemCode, $serviceCode, $operationCode);

            return [
                'class' => SerproConsumptionClass::Desconhecida,
                'is_essential' => false,
                'catalog_id' => null,
                'label' => null,
            ];
        }

        return [
            'class' => $entry->consumption_class,
            'is_essential' => (bool) $entry->is_essential,
            'catalog_id' => $entry->id,
            'label' => $entry->label,
        ];
    }

    private function alertUnknown(string $systemCode, string $serviceCode, string $operationCode): void
    {
        if (! (bool) config('serpro_usage.alert_unknown_class', true)) {
            return;
        }

        // Sem CNPJ / payload fiscal — só códigos de operação.
        Log::warning('serpro.usage.unknown_operation_class', [
            'system_code' => $systemCode,
            'service_code' => $serviceCode,
            'operation_code' => $operationCode,
        ]);

        $this->audit->record(
            action: 'serpro.usage.unknown_class',
            result: 'WARNING',
            context: [
                'system_code' => $systemCode,
                'service_code' => $serviceCode,
                'operation_code' => $operationCode,
            ],
        );
    }
}
