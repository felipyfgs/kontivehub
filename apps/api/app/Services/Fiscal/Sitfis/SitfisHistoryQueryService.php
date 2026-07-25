<?php

namespace App\Services\Fiscal\Sitfis;

use App\Models\Client;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use BackedEnum;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Histórico local SITFIS. Esta projeção nunca consulta nem enfileira o SERPRO.
 */
final class SitfisHistoryQueryService
{
    /**
     * @return array{
     *     client: array{id:int,legal_name:string,cnpj_masked:?string},
     *     searches: list<array<string, mixed>>
     * }
     */
    public function history(Office $office, Client $client): array
    {
        $this->assertClient($office, $client);

        $snapshots = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('system_code', (string) config('fiscal_monitoring.sitfis.system_code', 'INTEGRA_SITFIS'))
            ->where('service_code', (string) config('fiscal_monitoring.sitfis.service_code', 'SITFIS'))
            ->orderByDesc('observed_at')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get();

        return [
            'client' => [
                'id' => (int) $client->id,
                'legal_name' => (string) $client->legal_name,
                'cnpj_masked' => $this->cnpjMasked($client),
            ],
            'searches' => $this->canonicalSearches($snapshots)
                ->map(fn (FiscalSnapshot $snapshot): array => $this->publicSearch($snapshot))
                ->values()
                ->all(),
        ];
    }

    /**
     * Uma consulta pode possuir snapshots sucessores criados por reprocessamento local.
     * O maior version/id do mesmo run é a representação canônica dessa consulta.
     *
     * @param  Collection<int, FiscalSnapshot>  $snapshots
     * @return Collection<int, FiscalSnapshot>
     */
    private function canonicalSearches(Collection $snapshots): Collection
    {
        return $snapshots
            ->groupBy(fn (FiscalSnapshot $snapshot): string => 'run:'.(int) $snapshot->run_id)
            ->map(static fn (Collection $versions): FiscalSnapshot => $versions
                ->sortByDesc(static fn (FiscalSnapshot $snapshot): string => sprintf(
                    '%010d:%020d',
                    (int) $snapshot->version,
                    (int) $snapshot->id,
                ))
                ->first())
            ->sort(static function (FiscalSnapshot $left, FiscalSnapshot $right): int {
                $observed = $right->observed_at <=> $left->observed_at;
                if ($observed !== 0) {
                    return $observed;
                }

                $version = (int) $right->version <=> (int) $left->version;

                return $version !== 0 ? $version : ((int) $right->id <=> (int) $left->id);
            })
            ->values();
    }

    /** @return array<string, mixed> */
    private function publicSearch(FiscalSnapshot $snapshot): array
    {
        $evidenceId = $snapshot->evidence_artifact_id !== null
            ? (int) $snapshot->evidence_artifact_id
            : null;
        $situation = $snapshot->situation;

        return [
            'id' => (int) $snapshot->id,
            'observed_at' => $snapshot->observed_at?->toIso8601String(),
            'situation' => $situation instanceof BackedEnum ? $situation->value : $situation,
            'version' => (int) $snapshot->version,
            'is_current' => (bool) $snapshot->is_current,
            'evidence_artifact_id' => $evidenceId,
            'links' => [
                'evidence_download' => $evidenceId !== null
                    ? "/api/v1/fiscal/evidence/{$evidenceId}/download"
                    : null,
            ],
        ];
    }

    private function cnpjMasked(Client $client): ?string
    {
        $raw = $client->establishments()
            ->withoutGlobalScopes()
            ->orderByDesc('is_matrix')
            ->orderBy('id')
            ->value('cnpj');
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.***.***/****-'.substr($digits, -2);
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }
}
