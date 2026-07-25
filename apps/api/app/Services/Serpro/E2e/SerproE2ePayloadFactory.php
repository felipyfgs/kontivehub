<?php

namespace App\Services\Serpro\E2e;

use App\Models\Client;
use App\Models\Establishment;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;

/**
 * Monta businessData mínimo por operation_key a partir do catálogo + contexto piloto.
 * Não inventa coordenadas; só preenche campos de negócio necessários ao pedido.
 */
final class SerproE2ePayloadFactory
{
    public function __construct(
        private readonly OfficialServiceCatalogManifest $catalog,
    ) {}

    /**
     * @param  array{protocol?: string|null, period?: string|null, context?: array<string, mixed>}  $ctx
     * @return array{business_data: array<string, mixed>, payload: array<string, mixed>, notes: list<string>}
     */
    public function forOperation(string $operationKey, Client $client, array $ctx = []): array
    {
        $entry = $this->entry($operationKey);
        $notes = [];
        $dadosMode = (string) ($entry['dados_mode'] ?? 'JSON_STRING');
        $cnpj = $this->contributorCnpj($client);
        $period = (string) ($ctx['period'] ?? now()->subMonth()->format('Y-m'));
        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $protocol = isset($ctx['protocol']) ? (string) $ctx['protocol'] : null;

        $business = match (true) {
            $operationKey === 'sitfis.solicitar_protocolo' => [],
            $operationKey === 'sitfis.emitir_relatorio' => [
                'protocoloRelatorio' => $protocol ?? 'MISSING_PROTOCOL',
            ],
            str_starts_with($operationKey, 'pgdasd.consdeclaracao') => [
                'anoCalendario' => (string) $year,
            ],
            $operationKey === 'pgdasd.consultimadecrec' => [
                'periodoApuracao' => str_replace('-', '', $period),
            ],
            $operationKey === 'pgdasd.consdecrec' => [
                'numeroDeclaracao' => (string) ($ctx['context']['numeroDeclaracao'] ?? '0'),
            ],
            $operationKey === 'pgdasd.consextrato' => [
                'numeroDas' => (string) ($ctx['context']['numeroDas'] ?? '0'),
            ],
            str_starts_with($operationKey, 'pgdasd.gerardas') => [
                'periodoApuracao' => str_replace('-', '', $period),
            ],
            $operationKey === 'pgmei.dividaativa' => [
                'anoCalendario' => (string) $year,
            ],
            str_starts_with($operationKey, 'pgmei.gerardas') => [
                'periodoApuracao' => str_replace('-', '', $period),
            ],
            str_starts_with($operationKey, 'defis.consultimadecrec') => [
                'anoCalendario' => (string) ($year - 1),
            ],
            str_starts_with($operationKey, 'defis.') => [
                'anoCalendario' => (string) ($year - 1),
            ],
            $operationKey === 'regimeapuracao.consultaropcaoregime',
            $operationKey === 'regimeapuracao.consultarresolucao' => [
                'anoCalendario' => (string) $year,
            ],
            str_starts_with($operationKey, 'dctfweb.') => [
                'categoria' => 'GERAL_MENSAL',
                'anoPA' => $year,
                'mesPA' => $month,
            ],
            str_starts_with($operationKey, 'mit.') => [
                'anoPA' => $year,
                'mesPA' => $month,
            ],
            $operationKey === 'pagtoweb.pagamentos' => [
                'tamanhoDaPagina' => 10,
                'primeiroDaPagina' => 0,
            ],
            $operationKey === 'pagtoweb.comparrecadacao' => [
                'numeroDocumento' => (string) ($ctx['context']['numeroDocumento'] ?? '0'),
            ],
            str_ends_with($operationKey, '.pedidosparc'),
            str_ends_with($operationKey, '.parcelasparagerar') => [],
            str_ends_with($operationKey, '.obterparc') => [
                'numeroParcelamento' => (int) ($ctx['context']['numeroParcelamento'] ?? 1),
            ],
            str_ends_with($operationKey, '.detpagtoparc') => [
                'numeroParcelamento' => (int) ($ctx['context']['numeroParcelamento'] ?? 1),
                'anoMesParcela' => str_replace('-', '', $period),
            ],
            $this->isInstallmentGerardas($operationKey) => [
                'parcelaParaEmitir' => (int) ($ctx['context']['parcelaParaEmitir'] ?? 1),
            ],
            $operationKey === 'sicalc.consultaapoioreceitas' => [
                'codigoReceita' => '1708',
                'codigoReceitaExtensao' => '01',
                'dataPA' => now()->subMonth()->format('Y-m-d'),
                'valorImposto' => 100.00,
                'dataConsolidacao' => now()->format('Y-m-d'),
            ],
            str_starts_with($operationKey, 'sicalc.') => [
                'codigoReceita' => '1708',
                'codigoReceitaExtensao' => '01',
                'dataPA' => now()->subMonth()->format('Y-m-d'),
                'valorImposto' => 100.00,
                'dataConsolidacao' => now()->format('Y-m-d'),
            ],
            $operationKey === 'pnr_contador.consultar_vinculos' => [
                'size' => 10,
            ],
            $operationKey === 'pnr_contador.consultar_renuncias' => array_filter([
                'dtInicio' => $ctx['context']['dtInicio'] ?? null,
                'dtFim' => $ctx['context']['dtFim'] ?? null,
                'page' => $ctx['context']['page'] ?? 0,
                'pageSize' => $ctx['context']['pageSize'] ?? 10,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            $operationKey === 'pnr_contador.situacao_renuncia' => [
                'idSolicitacao' => (string) ($ctx['context']['idSolicitacao'] ?? '0'),
            ],
            $operationKey === 'pnr_contador.emitir_comprovante' => [
                'idRenuncia' => (string) ($ctx['context']['idRenuncia'] ?? '0'),
            ],
            $operationKey === 'eventosatualizacao.soliceventospj' => [
                'eventValue' => 'TODOS',
            ],
            $operationKey === 'eventosatualizacao.soliceventospf' => [
                'evento' => 'TODOS',
            ],
            str_starts_with($operationKey, 'eventosatualizacao.obter') => [
                'protocolo' => (string) ($ctx['context']['protocolo'] ?? '0'),
                'evento' => 'TODOS',
            ],
            $operationKey === 'ccmei.dadosccmei' => [
                'nomeCivil' => 'PILOTO',
                'cpf' => '00000000000',
                'cep' => '00000000',
                'numero' => '0',
                'bairro' => 'CENTRO',
                'municipio' => 'BRASILIA',
            ],
            $operationKey === 'autentica_procurador.envio_xml_assinado' => [
                // XML real vem do fluxo de onboarding; probe sem material = resposta de gate/validação.
            ],
            default => [],
        };

        if ($operationKey === 'sitfis.emitir_relatorio' && ($protocol === null || $protocol === '' || $protocol === 'MISSING_PROTOCOL')) {
            $notes[] = 'protocolo_ausente_no_contexto';
        }

        if ($dadosMode === 'EMPTY') {
            return [
                'business_data' => [],
                'payload' => ['dados' => ''],
                'notes' => $notes,
            ];
        }

        if ($operationKey === 'autentica_procurador.envio_xml_assinado') {
            $notes[] = 'requer_xml_assinado_do_fluxo_termo';

            return [
                'business_data' => ['xml' => ''],
                'payload' => ['dados' => json_encode(['xml' => ''], JSON_THROW_ON_ERROR)],
                'notes' => $notes,
            ];
        }

        // Contexto auxiliar para logs (não vaza CNPJ completo no artifact — só mask).
        $notes[] = 'contributor_mask='.substr($cnpj, 0, 4).'********'.substr($cnpj, -2);

        return [
            'business_data' => $business,
            'payload' => [
                'dados' => json_encode($business, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $operationKey): array
    {
        $manifest = $this->catalog->load();
        foreach ($manifest['entries'] as $entry) {
            if (($entry['operation_key'] ?? null) === $operationKey) {
                return $entry;
            }
        }

        return [];
    }

    private function contributorCnpj(Client $client): string
    {
        $est = Establishment::query()
            ->withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('is_matrix', true)
            ->where('is_active', true)
            ->first();

        if ($est !== null && is_string($est->cnpj) && $est->cnpj !== '') {
            return preg_replace('/\D+/', '', $est->cnpj) ?: (string) $est->cnpj;
        }

        $root = (string) ($client->root_cnpj ?? '');

        return $root;
    }

    private function isInstallmentGerardas(string $operationKey): bool
    {
        if (! str_ends_with($operationKey, '.gerardas')) {
            return false;
        }

        foreach (['parcsn', 'parcmei', 'pertsn', 'pertmei', 'relpsn', 'relpmei'] as $prefix) {
            if (str_starts_with($operationKey, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
