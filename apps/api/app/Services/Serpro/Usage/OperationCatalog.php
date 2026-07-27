<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproConsumptionClass;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $entry = DB::table('serpro_operation_versions as version')
            ->join('serpro_operations as operation', 'operation.id', '=', 'version.serpro_operation_id')
            ->where('operation.is_enabled', true)
            ->where('version.system_code', $systemCode)
            ->where('version.service_code', $serviceCode)
            ->where('version.operation_code', $operationCode)
            ->where(function ($query) use ($at): void {
                $query->whereNull('version.effective_from')
                    ->orWhere('version.effective_from', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('version.effective_to')
                    ->orWhere('version.effective_to', '>=', $at);
            })
            ->orderByDesc('version.effective_from')
            ->select([
                'operation.id',
                'operation.label',
                'operation.consumption_class',
                'version.billable_class',
            ])
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

        $class = SerproConsumptionClass::tryFrom(
            (string) ($entry->billable_class ?? $entry->consumption_class ?? '')
        ) ?? SerproConsumptionClass::Desconhecida;

        return [
            'class' => $class,
            'is_essential' => false,
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
