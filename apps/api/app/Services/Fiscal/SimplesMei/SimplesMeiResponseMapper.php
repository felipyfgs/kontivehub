<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Fiscal\SimplesMei\CcmeiDto;
use App\DTO\Fiscal\SimplesMei\DasGuideDto;
use App\DTO\Fiscal\SimplesMei\DasnSimeiDto;
use App\DTO\Fiscal\SimplesMei\DefisDto;
use App\DTO\Fiscal\SimplesMei\PgdasdDeclarationDto;
use App\DTO\Fiscal\SimplesMei\RegimeApuracaoDto;
use App\DTO\Fiscal\SimplesMei\SimplesMeiOperationDef;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\PgmeiDebtState;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDividaAtiva24Codec;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiYear;
use InvalidArgumentException;

/**
 * Converte resposta Integra em FiscalAdapterResult + DTO normalizado.
 */
final class SimplesMeiResponseMapper
{
    public function __construct(
        private readonly PgmeiDividaAtiva24Codec $pgmeiCodec24,
        private readonly ?RegimeCalendarOptionsCodec $regimeCalendarOptions = null,
        private readonly ?RegimeResolutionCodec $regimeResolution = null,
    ) {}

    public function map(
        SimplesMeiOperationDef $def,
        IntegraResponse $response,
        string $periodKey = '',
    ): FiscalAdapterResult {
        if (! $response->success) {
            return FiscalAdapterResult::failed(
                $response->errorMessage ?? 'Falha na chamada Integra Contador.',
                $response->errorCode ?? 'INTEGRA_FAILED',
                $def->coverage,
            );
        }

        try {
            $body = $response->body;
            // Preferir response.dados (contrato oficial); CCMEI exige dados
            // estruturados, sem fallback silencioso para o envelope bruto.
            $payload = $this->resolvePayload($response, $def);
            [$situation, $normalized, $findings] = $this->parse($def, $payload, $periodKey);
            $evidence = json_encode(
                $this->evidenceSafeBody($body, $payload, $def),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
        } catch (InvalidArgumentException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'INVALID_RESPONSE', $def->coverage);
        } catch (\JsonException) {
            return FiscalAdapterResult::failed(
                'Resposta CCMEI inválida ou ambígua.',
                'INVALID_RESPONSE',
                $def->coverage,
            );
        }

        if ($response->simulated) {
            $normalized['simulated'] = true;
            $normalized['evidence_productive'] = false;
        } else {
            $normalized['simulated'] = false;
            $normalized['evidence_productive'] = true;
        }

        // UP_TO_DATE exige evidência (bytes) — sempre presente aqui se success
        if ($situation === FiscalSituation::UpToDate && $evidence === '') {
            $situation = FiscalSituation::Unknown;
        }

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $situation,
            coverage: $def->coverage,
            evidenceBytes: $evidence,
            evidenceContentType: 'application/json',
            sourceVersion: $def->dtoVersion,
            normalized: $normalized,
            findings: $findings,
            itemsProcessed: 1,
            pagesProcessed: 1,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: FiscalSituation, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    private function parse(SimplesMeiOperationDef $def, array $body, string $periodKey): array
    {
        $service = strtoupper($def->serviceCode);
        $op = strtoupper($def->operationCode);

        if ($service === 'PGDASD' && in_array($op, [
            'CONSULTAR_ULTIMA_DECLARACAO_RECIBO',
            'CONSULTAR_RECIBO',
            'CONSULTAR_EXTRATO',
        ], true)) {
            return [FiscalSituation::Unknown, [
                'dto' => 'pgdasd_document_response',
                'operation' => $op,
                'status' => $body['status'] ?? 'OBSERVED',
            ], []];
        }

        if ($service === 'PGDASD' && in_array($op, [
            'MONITOR',
            'CONSULTAR_DECLARACAO',
        ], true)) {
            $dto = PgdasdDeclarationDto::fromIntegraBody($body, $periodKey);

            return [$dto->situation, $dto->toNormalized(), $this->findingsFromSituation($dto->situation, 'PGDASD', $dto->status)];
        }

        if ($service === 'PGDASD' && $op === 'GERAR_DAS') {
            $dto = DasGuideDto::fromIntegraBody($body, $periodKey, 'SIMPLES_NACIONAL');

            return [FiscalSituation::Attention, $dto->toNormalized(), [[
                'code' => 'DAS_EMITTED_PAYMENT_UNKNOWN',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'DAS emitido (pagamento não confirmado)',
                'detail' => 'Emissão assistida não implica quitação.',
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => false,
            ]]];
        }

        if ($service === 'PGDASD' && $op === 'TRANSMITIR') {
            return $this->mutatingDeclarationResult($body, 'PGDASD');
        }

        if ($service === 'DEFIS' && in_array($op, ['MONITOR', 'CONSULTAR'], true)) {
            if ($op === 'CONSULTAR') {
                $items = (new DefisDeclarationsCodec)->decode($body);

                return [FiscalSituation::UpToDate, [
                    'dto' => 'defis_declarations',
                    'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                    'declarations' => $items,
                ], []];
            }
            $year = strlen($periodKey) >= 4 ? substr($periodKey, 0, 4) : $periodKey;
            $dto = DefisDto::fromIntegraBody($body, $year);

            return [$dto->situation, $dto->toNormalized(), $this->findingsFromSituation($dto->situation, 'DEFIS', $dto->status)];
        }

        if ($service === 'DEFIS' && $op === 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO') {
            $year = (new DefisLatestDeclarationCodec)->assertCalendarYear(
                preg_match('/^(\d{4})/', $periodKey, $matches) === 1 ? $matches[1] : ($body['ano'] ?? null),
            );
            $decoded = (new DefisLatestDeclarationCodec)->decode($body, $year);

            return [FiscalSituation::UpToDate, [
                'dto' => 'defis_latest_declaration',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'operation_key' => 'defis.consultimadecrec',
                'calendar_year' => $decoded['calendar_year'],
                'documents' => array_map(static fn (array $document): array => ['kind' => $document['kind']], $decoded['documents']),
            ], []];
        }

        if ($service === 'DEFIS' && $op === 'CONSULTAR_DECLARACAO_RECIBO') {
            return [FiscalSituation::Unknown, [
                'dto' => 'defis_specific_declaration',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'operation_key' => 'defis.consdecrec',
                'documents' => [['kind' => 'RECIBO'], ['kind' => 'DECLARACAO']],
            ], []];
        }

        if ($service === 'DEFIS' && $op === 'TRANSMITIR') {
            return $this->mutatingDeclarationResult($body, 'DEFIS');
        }

        if ($service === 'REGIME_APURACAO' && $op === 'CONSULTAR_ANOS_CALENDARIOS') {
            $items = ($this->regimeCalendarOptions ?? new RegimeCalendarOptionsCodec)->decode($body);

            return [FiscalSituation::UpToDate, [
                'dto' => 'regime_apuracao_calendars',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'calendar_options' => $items,
            ], []];
        }

        if ($service === 'REGIME_APURACAO' && $op === 'CONSULTAR_RESOLUCAO') {
            $codec = $this->regimeResolution ?? new RegimeResolutionCodec;
            $year = preg_match('/^(\d{4})/', $periodKey, $matches) === 1
                ? (int) $matches[1]
                : (int) now()->format('Y');
            // Fail-closed: valida Base64 estrito; bytes só no cofre (adapter).
            $decoded = $codec->decode($body, $year);

            return [FiscalSituation::UpToDate, [
                'dto' => 'regime_apuracao_resolucao',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'operation_key' => RegimeResolutionCodec::OPERATION_KEY,
                'calendar_year' => $decoded['calendar_year'],
                'byte_size' => $decoded['byte_size'],
                'content_type' => $decoded['content_type'],
                'resolution' => $codec->publicDescriptorMeta($decoded['byte_size']),
            ], []];
        }

        if ($service === 'REGIME_APURACAO' && $op === 'CONSULTAR') {
            $decoded = (new RegimeOptionCodec)->decode($body, $periodKey);

            return [FiscalSituation::UpToDate, [
                'dto' => 'regime_apuracao_opcao',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'calendar_options' => [[
                    'calendar_year' => $decoded['calendar_year'],
                    'regime_apuracao' => $decoded['regime_apuracao'],
                ]],
            ], []];
        }

        if ($service === 'REGIME_APURACAO' && in_array($op, ['MONITOR', 'CONSULTAR'], true)) {
            $dto = RegimeApuracaoDto::fromIntegraBody($body);
            $situation = $dto->currentRegime->value === 'UNKNOWN'
                ? FiscalSituation::Unknown
                : FiscalSituation::UpToDate;

            return [$situation, $dto->toNormalized(), []];
        }

        if ($service === 'PGMEI' && in_array($op, ['MONITOR', 'CONSULTAR'], true)) {
            $year = preg_match('/^(\d{4})/', $periodKey, $matches) === 1
                ? (int) $matches[1]
                : (int) now()->format('Y');

            try {
                $decoded = $this->pgmeiCodec24->decodeDados($body, PgmeiYear::assertValid($year));
                $state = $decoded['items_count'] > 0
                    ? PgmeiDebtState::HasActiveDebt
                    : PgmeiDebtState::NoActiveDebt;
                $situation = $state === PgmeiDebtState::HasActiveDebt
                    ? FiscalSituation::Pending
                    : FiscalSituation::UpToDate;
                $normalized = [
                    'dto' => 'pgmei_divida_ativa',
                    'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                    'calendar_year' => $decoded['calendar_year'],
                    'status' => $state->value,
                    'debt_state' => $state->value,
                    'items_count' => $decoded['items_count'],
                    'total_cents' => $decoded['total_cents'],
                    'items' => $decoded['items'],
                    'regime_family' => 'MEI',
                    'payment_inferred' => false,
                ];

                return [
                    $situation,
                    $normalized,
                    $this->findingsFromSituation($situation, 'PGMEI', $state->value),
                ];
            } catch (\Throwable $e) {
                return [FiscalSituation::Unknown, [
                    'dto' => 'pgmei_divida_ativa',
                    'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                    'calendar_year' => $year,
                    'status' => PgmeiDebtState::Unverified->value,
                    'debt_state' => PgmeiDebtState::Unverified->value,
                    'items_count' => 0,
                    'total_cents' => 0,
                    'items' => [],
                    'reason' => 'INVALID_OR_AMBIGUOUS_RESPONSE',
                    'regime_family' => 'MEI',
                    'payment_inferred' => false,
                ], $this->findingsFromSituation(
                    FiscalSituation::Unknown,
                    'PGMEI',
                    PgmeiDebtState::Unverified->value,
                )];
            }
        }

        if ($service === 'PGMEI' && $op === 'GERAR_DAS') {
            $dto = DasGuideDto::fromIntegraBody($body, $periodKey, 'MEI');

            return [FiscalSituation::Attention, $dto->toNormalized(), [[
                'code' => 'DAS_MEI_EMITTED_PAYMENT_UNKNOWN',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'DAS MEI emitido (pagamento não confirmado)',
                'detail' => 'Emissão assistida não implica quitação.',
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => false,
            ]]];
        }

        if ($service === 'CCMEI' && $op === 'CONSULTAR_SITUACAO_CADASTRAL') {
            $decoded = (new CcmeiRegistrationStatusCodec)->decode($body);

            return [$decoded['situation'], [
                'dto' => 'ccmei_registration_status',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'operation_key' => 'ccmei.ccmeisitcadastral',
                'status' => $decoded['status'],
                'enquadrado_mei' => $decoded['enquadrado_mei'],
                'situation' => $decoded['situation']->value,
                'count' => $decoded['count'],
            ], $this->findingsFromSituation($decoded['situation'], 'CCMEI', $decoded['status'])];
        }

        if ($service === 'CCMEI' && in_array($op, ['MONITOR', 'CONSULTAR'], true)) {
            $dto = CcmeiDto::fromIntegraBody($body);

            return [$dto->situation, $dto->toNormalized(), $this->findingsFromSituation($dto->situation, 'CCMEI', $dto->status)];
        }

        if ($service === 'DASN_SIMEI' && in_array($op, ['MONITOR', 'CONSULTAR'], true)) {
            $year = strlen($periodKey) >= 4 ? substr($periodKey, 0, 4) : $periodKey;
            $dto = DasnSimeiDto::fromIntegraBody($body, $year);

            return [$dto->situation, $dto->toNormalized(), $this->findingsFromSituation($dto->situation, 'DASN_SIMEI', $dto->status)];
        }

        if ($service === 'DASN_SIMEI' && $op === 'TRANSMITIR') {
            return $this->mutatingDeclarationResult($body, 'DASN_SIMEI');
        }

        throw new InvalidArgumentException(
            "Operação Simples/MEI sem mapper: {$def->systemCode}/{$def->serviceCode}/{$def->operationCode}"
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: FiscalSituation, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    private function mutatingDeclarationResult(array $body, string $service): array
    {
        return [
            FiscalSituation::Attention,
            [
                'dto' => 'declaration_transmit',
                'service' => $service,
                'status' => $body['status'] ?? 'UNKNOWN',
                'mutability' => 'MUTATING',
            ],
            [[
                'code' => 'MUTATING_TRANSMIT',
                'severity' => FiscalFindingSeverity::High->value,
                'title' => 'Transmissão de declaração',
                'detail' => 'Operação mutante — exige flags e aprovação.',
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => false,
            ]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findingsFromSituation(FiscalSituation $situation, string $service, string $status): array
    {
        if ($situation === FiscalSituation::Pending) {
            return [[
                'code' => "{$service}_PENDING",
                'severity' => FiscalFindingSeverity::High->value,
                'title' => "Pendência {$service}",
                'detail' => "Status oficial: {$status}",
                'situation' => FiscalSituation::Pending->value,
                'creates_pending' => true,
            ]];
        }

        if ($situation === FiscalSituation::Unknown) {
            return [[
                'code' => "{$service}_INCONCLUSIVE",
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => "Competência inconclusiva ({$service})",
                'detail' => 'Fonte não confirmou entrega nem pendência — situação UNKNOWN.',
                'situation' => FiscalSituation::Unknown->value,
                'creates_pending' => false,
            ]];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(IntegraResponse $response, SimplesMeiOperationDef $def): array
    {
        $dados = $response->dados;
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($dados)) {
            return $dados;
        }
        if (isset($response->body['dados'])) {
            $inner = $response->body['dados'];
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            if (is_array($inner)) {
                return $inner;
            }
        }

        // O fake contratual usado em CI entrega o corpo já decodificado em
        // `data`; produção continua exigindo `dados` JSON do envelope SERPRO.
        if (strtoupper($def->serviceCode) === 'CCMEI'
            && isset($response->body['data'])
            && is_array($response->body['data'])) {
            return ['data' => $response->body['data']];
        }

        if (strtoupper($def->serviceCode) === 'CCMEI') {
            throw new InvalidArgumentException('Resposta CCMEI inválida ou ambígua.');
        }

        return $response->body;
    }

    /**
     * Evidence JSON: para ops documentais, não embute Base64 de PDF.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function evidenceSafeBody(array $body, array $payload, SimplesMeiOperationDef $def): array
    {
        $service = strtoupper($def->serviceCode);
        $op = strtoupper($def->operationCode);

        // 104: nunca embute Base64/texto da resolução na evidência JSON do snapshot.
        if ($service === 'REGIME_APURACAO' && $op === 'CONSULTAR_RESOLUCAO') {
            return [
                'status' => $body['status'] ?? null,
                'dados' => [
                    'textoResolucao' => ['sanitized' => true, 'omitted' => true],
                ],
                'operation_key' => RegimeResolutionCodec::OPERATION_KEY,
            ];
        }

        // 103: a resposta oficial pode conter CNPJ e documentos Base64.
        // A evidência operacional conserva apenas a opção anual já validada.
        if ($service === 'REGIME_APURACAO' && $op === 'CONSULTAR') {
            $option = (new RegimeOptionCodec)->decode($payload, $payload['anoCalendario'] ?? '');

            return [
                'status' => $body['status'] ?? null,
                'dados' => [
                    'anoCalendario' => $option['calendar_year'],
                    'regimeEscolhido' => $option['regime_apuracao'],
                ],
                'operation_key' => RegimeOptionCodec::OPERATION_KEY,
            ];
        }

        // 142: o identificador de declaração e a data/hora não são necessários
        // para o monitor e não podem ser copiados à evidência operacional.
        if ($service === 'DEFIS' && $op === 'CONSULTAR') {
            return [
                'status' => $body['status'] ?? null,
                'dados' => (new DefisDeclarationsCodec)->decode($payload),
                'operation_key' => 'defis.consdeclaracao',
            ];
        }

        // 143: PDFs e idDefis ficam estritamente fora da evidência JSON; o
        // adapter valida os bytes e os coloca no cofre antes de publicar descritores.
        if ($service === 'DEFIS' && $op === 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO') {
            return [
                'status' => $body['status'] ?? null,
                'dados' => ['sanitized' => true, 'omitted' => true, 'document_kinds' => ['RECIBO', 'DECLARACAO']],
                'operation_key' => 'defis.consultimadecrec',
            ];
        }

        if ($service === 'DEFIS' && $op === 'CONSULTAR_DECLARACAO_RECIBO') {
            return [
                'status' => $body['status'] ?? null,
                'dados' => ['sanitized' => true, 'omitted' => true, 'document_kinds' => ['RECIBO', 'DECLARACAO']],
                'operation_key' => 'defis.consdecrec',
            ];
        }

        if ($service === 'CCMEI' && $op === 'CONSULTAR_SITUACAO_CADASTRAL') {
            $decoded = (new CcmeiRegistrationStatusCodec)->decode($payload);

            return [
                'status' => $body['status'] ?? null,
                'dados' => [
                    'status' => $decoded['status'],
                    'enquadrado_mei' => $decoded['enquadrado_mei'],
                    'situation' => $decoded['situation']->value,
                    'count' => $decoded['count'],
                ],
                'operation_key' => 'ccmei.ccmeisitcadastral',
            ];
        }

        if ($service === 'CCMEI') {
            $dto = CcmeiDto::fromIntegraBody($payload);

            return [
                'status' => $body['status'] ?? null,
                'dados' => $dto->toNormalized(),
            ];
        }

        if ($service !== 'PGDASD') {
            return $body;
        }
        if (! in_array($op, [
            'CONSULTAR_ULTIMA_DECLARACAO_RECIBO',
            'CONSULTAR_RECIBO',
            'CONSULTAR_EXTRATO',
        ], true)) {
            return ['dados' => $payload, 'status' => $body['status'] ?? null];
        }

        $isEncodedBlob = static function (string $value): bool {
            $candidate = preg_replace('/\s+/', '', $value) ?? '';
            $length = strlen($candidate);
            if ($length < 8 || $length % 4 !== 0
                || strspn($candidate, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== $length
            ) {
                return false;
            }
            $decoded = base64_decode($candidate, true);

            return is_string($decoded)
                && (str_starts_with($decoded, '%PDF') || $length >= 128);
        };
        $strip = static function (array $node) use (&$strip, $isEncodedBlob): array {
            foreach ($node as $field => &$value) {
                if (in_array((string) $field, ['pdf', 'pdfNotificacao', 'pdfDarf'], true) && is_string($value)) {
                    $value = ['sanitized' => true, 'omitted' => true];

                    continue;
                }
                if (is_string($value) && $isEncodedBlob($value)) {
                    $value = ['sanitized' => true, 'omitted' => true];

                    continue;
                }
                if (is_array($value)) {
                    $value = $strip($value);
                }
            }
            unset($value);

            return $node;
        };

        return [
            'status' => $body['status'] ?? null,
            'dados' => $strip($payload),
        ];
    }
}
