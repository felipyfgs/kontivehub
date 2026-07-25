<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalModuleKey;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMonitoringModuleExclusion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Opt-out de carteira por módulo/submodule — não altera tax_regime nem apaga CRM.
 * submodule '' = escopo do módulo inteiro; para Simples/MEI exige PGDASD|PGMEI.
 */
final class MonitoringModuleMembershipService
{
    /** Normaliza submodule (uppercase) ou '' (módulo inteiro). */
    public function normalizeSubmodule(?string $submodule): string
    {
        return strtoupper(trim((string) $submodule));
    }

    /**
     * Cliente elegível pelo regime do módulo/submodule (sem considerar exclusão).
     */
    public function isEligible(Client $client, FiscalModuleKey $module, ?string $submodule): bool
    {
        if ($module === FiscalModuleKey::Dashboard) {
            return false;
        }

        if (! $client->is_active || $client->matrix_client_id !== null) {
            return false;
        }

        if ($module === FiscalModuleKey::SimplesMei) {
            $sub = $this->normalizeSubmodule($submodule);
            $regime = match ($sub) {
                'PGDASD', 'PGDAS', 'SIMPLES', 'SIMPLES_NACIONAL' => TaxRegimeCode::SimplesNacional,
                'PGMEI', 'MEI' => TaxRegimeCode::Mei,
                default => null,
            };
            if ($regime === null) {
                return false;
            }

            return in_array(
                (string) $client->tax_regime,
                $regime->storageFilterValues(),
                true,
            );
        }

        return true;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array{excluded:int, errors:list<array{client_id:int, message:string}>}
     */
    public function exclude(
        Office $office,
        FiscalModuleKey $module,
        array $clientIds,
        ?string $submodule = null,
        ?int $actorId = null,
    ): array {
        if ($module === FiscalModuleKey::Dashboard) {
            throw new RuntimeException('Módulo dashboard não possui carteira.');
        }

        $sub = $this->normalizeSubmodule($submodule);
        if ($module === FiscalModuleKey::SimplesMei && $sub === '') {
            throw new RuntimeException('Submodule obrigatório para Simples/MEI (PGDASD ou PGMEI).');
        }

        $excluded = 0;
        $errors = [];

        foreach (array_unique(array_map('intval', $clientIds)) as $clientId) {
            try {
                $client = Client::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereKey($clientId)
                    ->first();
                if ($client === null) {
                    $errors[] = ['client_id' => $clientId, 'message' => 'Cliente não encontrado no tenant.'];

                    continue;
                }
                if (! $this->isEligible($client, $module, $sub === '' ? null : $sub)) {
                    $errors[] = ['client_id' => $clientId, 'message' => 'Cliente não elegível para este módulo.'];

                    continue;
                }

                OfficeMonitoringModuleExclusion::query()->updateOrCreate(
                    [
                        'office_id' => $office->id,
                        'client_id' => $clientId,
                        'module_key' => $module->value,
                        'submodule' => $sub,
                    ],
                    [
                        'excluded_by' => $actorId,
                    ],
                );
                $excluded++;
            } catch (\Throwable $e) {
                $errors[] = ['client_id' => $clientId, 'message' => $e->getMessage()];
            }
        }

        return compact('excluded', 'errors');
    }

    /**
     * Remove opt-out (reinclui na carteira se ainda elegível).
     *
     * @param  list<int>  $clientIds
     * @return array{included:int, errors:list<array{client_id:int, message:string}>}
     */
    public function include(
        Office $office,
        FiscalModuleKey $module,
        array $clientIds,
        ?string $submodule = null,
    ): array {
        if ($module === FiscalModuleKey::Dashboard) {
            throw new RuntimeException('Módulo dashboard não possui carteira.');
        }

        $sub = $this->normalizeSubmodule($submodule);
        if ($module === FiscalModuleKey::SimplesMei && $sub === '') {
            throw new RuntimeException('Submodule obrigatório para Simples/MEI (PGDASD ou PGMEI).');
        }

        $included = 0;
        $errors = [];

        foreach (array_unique(array_map('intval', $clientIds)) as $clientId) {
            try {
                $client = Client::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereKey($clientId)
                    ->first();
                if ($client === null) {
                    $errors[] = ['client_id' => $clientId, 'message' => 'Cliente não encontrado no tenant.'];

                    continue;
                }
                if (! $this->isEligible($client, $module, $sub === '' ? null : $sub)) {
                    $errors[] = [
                        'client_id' => $clientId,
                        'message' => 'Cliente fora do regime do módulo; não é possível incluir sem alterar o cadastro.',
                    ];

                    continue;
                }

                OfficeMonitoringModuleExclusion::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->where('client_id', $clientId)
                    ->where('module_key', $module->value)
                    ->where('submodule', $sub)
                    ->delete();

                $included++;
            } catch (\Throwable $e) {
                $errors[] = ['client_id' => $clientId, 'message' => $e->getMessage()];
            }
        }

        return compact('included', 'errors');
    }

    /**
     * @return Collection<int, OfficeMonitoringModuleExclusion>
     */
    public function listExclusions(
        Office $office,
        FiscalModuleKey $module,
        ?string $submodule = null,
    ): Collection {
        $sub = $this->normalizeSubmodule($submodule);
        $q = OfficeMonitoringModuleExclusion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('module_key', $module->value)
            ->orderByDesc('id');

        if ($sub !== '') {
            $q->where(function (Builder $inner) use ($sub): void {
                $inner->where('submodule', '')
                    ->orWhere('submodule', $sub);
            });
        }

        return $q->get();
    }

    /**
     * Aplica filtro de exclusão ao query de IDs da carteira.
     */
    public function applyExclusionScope(
        Builder|QueryBuilder $q,
        Office $office,
        FiscalModuleKey $module,
        ?string $submodule,
    ): void {
        if ($module === FiscalModuleKey::Dashboard) {
            return;
        }

        $sub = $this->normalizeSubmodule($submodule);

        $q->whereNotExists(function (QueryBuilder $exists) use ($office, $module, $sub): void {
            $exists->selectRaw('1')
                ->from('office_monitoring_module_exclusions as ome')
                ->whereColumn('ome.client_id', 'clients.id')
                ->where('ome.office_id', $office->id)
                ->where('ome.module_key', $module->value);

            if ($sub !== '') {
                $exists->where(function (QueryBuilder $inner) use ($sub): void {
                    $inner->where('ome.submodule', '')
                        ->orWhere('ome.submodule', $sub);
                });
            }
        });
    }
}
