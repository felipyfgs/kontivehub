<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Codecs das dez operações declarativas mutantes em produção.
 *
 * Recebe somente parâmetros públicos já curados. Identidade do contribuinte é
 * argumento confiável do servidor e coordenadas nunca fazem parte do retorno.
 */
final class DeclarationMutationPayloadCodec
{
    public function __construct(
        private readonly DeclarationOperationRegistry $registry,
        private readonly DeclarationOperationInputValidator $inputs,
        private readonly OfficialServiceCatalogManifest $manifest,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function encode(string $actionId, array $params, string $trustedContributorCnpj): array
    {
        $operationKey = $this->registry->operationKeyFor($actionId);
        $entry = $this->manifest->findByOperationKey($this->manifest->load(), $operationKey);
        $this->assertProductionMutation($entry);
        $params = $this->inputs->validate($operationKey, $params);
        $cnpj = $this->cnpj($trustedContributorCnpj);

        return match ($operationKey) {
            'pgdasd.transdeclaracao' => $this->pgdasTransmit($params, $cnpj),
            'pgdasd.gerardas' => $this->pgdasGenerate($params),
            'pgdasd.gerardascobranca', 'pgdasd.gerardasprocesso' => [
                'periodoApuracao' => (int) $this->periodNumber($params['period_key']),
            ],
            'pgdasd.gerardasavulso' => $this->pgdasAvulso($params),
            'defis.transdeclaracao' => $this->defisTransmit($params),
            'dctfweb.gerarguia' => $this->dctfPayload($params, includeProposal: true),
            'dctfweb.transdeclaracao' => $this->dctfTransmit($params),
            'dctfweb.gerarguiaandamento' => $this->dctfPayload($params),
            'mit.encapuracao' => $this->mitClose($params),
            default => throw new HttpException(422, 'OPERATION_MUTATION_CODEC_MISSING'),
        };
    }

    /** @param array<string, mixed> $entry */
    private function assertProductionMutation(array $entry): void
    {
        if (($entry['official_state'] ?? null) !== SerproOfficialState::Production->value) {
            throw new HttpException(422, 'OPERATION_NOT_PRODUCTION');
        }
        if (! (bool) ($entry['is_mutating'] ?? false)) {
            throw new HttpException(422, 'OPERATION_IS_READ_ONLY');
        }
        if (! in_array((string) ($entry['platform_support'] ?? ''), [
            SerproPlatformSupport::Implemented->value,
            SerproPlatformSupport::ProductionValidated->value,
        ], true)) {
            throw new HttpException(422, 'OPERATION_NOT_IMPLEMENTED');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function pgdasTransmit(array $params, string $cnpj): array
    {
        $body = $this->businessPayload($params, [
            'indicadorTransmissao',
            'indicadorComparacao',
            'declaracao',
        ]);

        return [
            ...$body,
            'cnpjCompleto' => $cnpj,
            'pa' => (int) $this->periodNumber($params['period_key']),
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function pgdasGenerate(array $params): array
    {
        return array_filter([
            'periodoApuracao' => $this->periodNumber($params['period_key']),
            'dataConsolidacao' => isset($params['consolidation_date'])
                ? str_replace('-', '', $params['consolidation_date'])
                : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function pgdasAvulso(array $params): array
    {
        $body = $this->businessPayload($params, ['listaTributos']);

        return array_filter([
            ...$body,
            'periodoApuracao' => $this->periodNumber($params['period_key']),
            'dataConsolidacao' => isset($params['consolidation_date'])
                ? (int) str_replace('-', '', $params['consolidation_date'])
                : null,
            'prorrogacaoEspecial' => $params['special_extension'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function defisTransmit(array $params): array
    {
        $body = $this->businessPayload($params, ['inatividade', 'empresa']);

        return [
            ...$body,
            'ano' => (int) $params['calendar_year'],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function dctfPayload(array $params, bool $includeProposal = false): array
    {
        [$year, $month] = explode('-', $params['period_key'], 2);
        $payload = array_filter([
            'categoria' => $params['category'],
            'anoPA' => $year,
            'mesPA' => $month,
            'diaPA' => isset($params['day']) ? str_pad((string) $params['day'], 2, '0', STR_PAD_LEFT) : null,
            'cnoAfericao' => $params['cno'] ?? null,
            'numProcReclamatoria' => $params['labor_process'] ?? null,
            'idsSistemaOrigem' => $params['source_system_ids'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($includeProposal && isset($params['proposal_date'])) {
            $payload['DataAcolhimentoProposta'] = (int) str_replace('-', '', $params['proposal_date']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function dctfTransmit(array $params): array
    {
        return [
            ...$this->dctfPayload($params),
            ...array_filter([
                'numeroReciboEntrega' => isset($params['receipt_number'])
                    ? $this->numericString('receipt_number', $params['receipt_number'])
                    : null,
                'xmlAssinadoBase64' => $params['signed_xml_base64'],
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function mitClose(array $params): array
    {
        $body = $this->businessPayload($params);
        [$year, $month] = explode('-', $params['period_key'], 2);

        // Período é autoridade do preflight; um valor importado não o sobrescreve.
        $body['PeriodoApuracao'] = [
            'MesApuracao' => (int) $month,
            'AnoApuracao' => (int) $year,
        ];

        return $body;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function businessPayload(array $params, array $required = []): array
    {
        $body = $params['business_payload'] ?? null;
        if (! is_array($body) || array_is_list($body)) {
            $this->fail('params.business_payload', 'Informe um objeto JSON de negócio.');
        }
        foreach ($required as $field) {
            if (! array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
                $this->fail('params.business_payload.'.$field, 'Campo obrigatório pelo contrato oficial.');
            }
        }

        return $body;
    }

    private function periodNumber(string $periodKey): string
    {
        return str_replace('-', '', $periodKey);
    }

    private function numericString(string $field, mixed $value): int
    {
        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            $this->fail('params.'.$field, 'Informe somente dígitos.');
        }

        return (int) $value;
    }

    private function cnpj(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 14) {
            $this->fail('client_id', 'CNPJ completo do contribuinte não está disponível.');
        }

        return $digits;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
