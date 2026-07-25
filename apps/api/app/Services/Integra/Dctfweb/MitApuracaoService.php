<?php

namespace App\Services\Integra\Dctfweb;

use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Enums\MitEncerramentoStatus;
use App\Models\Client;
use App\Models\DctfwebDeclaration;
use App\Models\MitApuracao;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Projeção MIT — estado independente da transmissão DCTFWeb (9.3).
 * Encerrado MIT sem recibo DCTFWeb → transmissão permanece UNKNOWN/PENDING.
 */
final class MitApuracaoService
{
    public function __construct(
        private readonly DctfwebCompetenceResolver $competences,
    ) {}

    public function findOrCreate(
        Office $office,
        Client $client,
        string $periodKey,
    ): MitApuracao {
        $periodKey = $this->competences->normalizePeriodKey($periodKey);
        $competence = $this->competences->resolve($office, $client, $periodKey, DctfwebCodes::CATEGORY_MIT);

        $existing = MitApuracao::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('period_key', $periodKey)
            ->first();

        if ($existing !== null) {
            if ($existing->competence_id === null) {
                $existing->forceFill(['competence_id' => $competence->id])->save();
            }
            // Sempre re-sincroniza espelho DCTFWeb a partir da projeção real (nunca infere)
            $this->syncDctfwebMirror($existing);

            return $existing->fresh();
        }

        $mit = MitApuracao::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'competence_id' => $competence->id,
            'period_key' => $periodKey,
            'encerramento_status' => MitEncerramentoStatus::Unknown,
            'situacao_status' => 'UNKNOWN',
            'dctfweb_transmission_status' => DctfwebTransmissionStatus::Unknown,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Partial,
        ]);

        $this->syncDctfwebMirror($mit);

        return $mit->fresh();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function projectSituacao(
        Office $office,
        Client $client,
        string $periodKey,
        array $body,
    ): MitApuracao {
        $mit = $this->findOrCreate($office, $client, $periodKey);

        $encerrado = $this->isEncerrado($body);
        $situacao = strtoupper((string) ($body['textoSituacao'] ?? $body['situacao'] ?? $body['status'] ?? 'UNKNOWN'));
        if (strlen($situacao) > 30) {
            $situacao = substr($situacao, 0, 30);
        }

        $encStatus = $encerrado
            ? MitEncerramentoStatus::Encerrado
            : (strtoupper((string) ($body['status'] ?? '')) === 'ABERTO'
                ? MitEncerramentoStatus::Open
                : MitEncerramentoStatus::Unknown);

        $situation = match ($encStatus) {
            MitEncerramentoStatus::Encerrado => FiscalSituation::UpToDate,
            MitEncerramentoStatus::Open => FiscalSituation::Pending,
            default => FiscalSituation::Unknown,
        };

        // UP_TO_DATE para MIT encerrado refere-se só à etapa MIT — cobertura PARTIAL
        // (não implica DCTFWeb transmitida).
        if ($encStatus === MitEncerramentoStatus::Encerrado) {
            $situation = FiscalSituation::Attention; // parcial: etapa MIT ok, DCTFWeb independente
        }

        $mit->forceFill([
            'encerramento_status' => $encStatus,
            'situacao_status' => $situacao !== '' ? $situacao : 'UNKNOWN',
            'situation' => $situation,
            'coverage' => FiscalCoverage::Partial,
            'encerrado_at' => $encerrado
                ? ($this->timeFrom($body) ?? CarbonImmutable::now())
                : $mit->encerrado_at,
            'observed_at' => CarbonImmutable::now(),
            'metadata' => [
                'keys' => array_keys($body),
                'raw_status' => $body['status'] ?? null,
            ],
        ])->save();

        $this->syncDctfwebMirror($mit);

        return $mit->fresh();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function projectApuracao(
        Office $office,
        Client $client,
        string $periodKey,
        array $body,
    ): MitApuracao {
        // Reusa projeção de situação; apuração traz campos extras em metadata
        $mit = $this->projectSituacao($office, $client, $periodKey, $body);
        $meta = $mit->metadata ?? [];
        $meta['apuracao'] = [
            'valor' => $body['valor'] ?? $body['valorTotal'] ?? $body['valorTotalApurado'] ?? null,
            'periodo' => $body['periodoApuracao'] ?? $body['periodo'] ?? $periodKey,
            'keys' => array_keys($body),
            'dados_apuracao_count' => is_array($body['dadosApuracaoMit'] ?? null)
                ? count($body['dadosApuracaoMit'])
                : null,
        ];
        $mit->forceFill(['metadata' => $meta])->save();

        return $mit->fresh();
    }

    /**
     * Persiste a lista 317 como projeções MIT locais, sem evidência documental.
     *
     * @param  list<array{period_key:string,id_apuracao:int,situacao:int,data_encerramento:?string,evento_especial:bool,valor_total_apurado:float|int}>  $items
     * @return list<MitApuracao>
     */
    public function projectListaApuracoes(Office $office, Client $client, array $items): array
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new \InvalidArgumentException('Cliente não pertence ao escritório ativo.');
        }

        return DB::transaction(function () use ($office, $client, $items): array {
            $projected = [];
            foreach ($items as $item) {
                $periodKey = $this->competences->normalizePeriodKey($item['period_key']);
                $mit = $this->projectApuracao($office, $client, $periodKey, [
                    'situacao' => $item['situacao'],
                    'dataEncerramento' => $item['data_encerramento'],
                    'valorTotalApurado' => $item['valor_total_apurado'],
                ]);

                $metadata = is_array($mit->metadata) ? $mit->metadata : [];
                $metadata['lista_apuracoes_317'] = [
                    'id_apuracao' => $item['id_apuracao'],
                    'situacao' => $item['situacao'],
                    'data_encerramento' => $item['data_encerramento'],
                    'evento_especial' => $item['evento_especial'],
                    'valor_total_apurado' => $item['valor_total_apurado'],
                ];
                $mit->forceFill([
                    'metadata' => $metadata,
                    'observed_at' => CarbonImmutable::now(),
                ])->save();
                $projected[] = $mit->fresh();
            }

            return $projected;
        });
    }

    /**
     * Espelha transmission_status da declaração DCTFWeb sem inferir.
     */
    public function syncDctfwebMirror(MitApuracao $mit): void
    {
        $decl = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $mit->office_id)
            ->where('client_id', $mit->client_id)
            ->where('period_key', $mit->period_key)
            ->first();

        $tx = $decl?->transmission_status ?? DctfwebTransmissionStatus::Unknown;

        // Se MIT encerrado e DCTFWeb sem recibo → força PENDING (não TRANSMITTED)
        if (
            $mit->encerramento_status === MitEncerramentoStatus::Encerrado
            && ! $tx->isConfirmed()
        ) {
            $tx = $tx === DctfwebTransmissionStatus::Unknown
                ? DctfwebTransmissionStatus::Pending
                : $tx;
        }

        if ($mit->dctfweb_transmission_status !== $tx) {
            $mit->forceFill(['dctfweb_transmission_status' => $tx])->save();
        }
    }

    /**
     * @return LengthAwarePaginator<int, MitApuracao>
     */
    public function paginate(Office $office, int $perPage = 50, ?int $clientId = null): LengthAwarePaginator
    {
        $q = MitApuracao::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('period_key');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }

        return $q->paginate($perPage);
    }

    public function findForOffice(Office $office, int $id): ?MitApuracao
    {
        return MitApuracao::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isEncerrado(array $body): bool
    {
        if (! empty($body['encerrado']) || ! empty($body['closed']) || ! empty($body['encerramento'])) {
            $v = $body['encerrado'] ?? $body['closed'] ?? $body['encerramento'];
            if (is_bool($v)) {
                return $v;
            }
            if (is_string($v)) {
                return in_array(strtoupper($v), ['1', 'TRUE', 'SIM', 'S', 'YES', 'ENCERRADO'], true);
            }
        }

        $status = strtoupper((string) ($body['textoSituacao'] ?? $body['status'] ?? $body['situacao'] ?? ''));

        return in_array($status, ['ENCERRADO', 'ENCERRADA', 'CLOSED', 'FECHADO', 'FECHADA'], true);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function timeFrom(array $body): ?CarbonImmutable
    {
        foreach (['dataEncerramento', 'encerrado_at', 'closed_at'] as $k) {
            if (! empty($body[$k]) && is_string($body[$k])) {
                try {
                    return CarbonImmutable::parse($body[$k]);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
