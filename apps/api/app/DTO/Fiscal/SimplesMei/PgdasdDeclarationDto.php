<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use InvalidArgumentException;

/**
 * DTO versionado de declaração PGDAS-D (contrato de adapter).
 *
 * @phpstan-type Raw array<string, mixed>
 */
final readonly class PgdasdDeclarationDto
{
    public const VERSION = '1';

    public function __construct(
        public string $version,
        public string $competence,
        public string $status,
        public ?string $receiptNumber,
        public ?string $declarationId,
        public ?string $transmittedAt,
        public FiscalSituation $situation,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body, string $fallbackCompetence = ''): self
    {
        // Aceita payload legado (dto_version) ou contrato oficial SERPRO (periodos/operacoes)
        $version = (string) ($body['dto_version'] ?? $body['version'] ?? self::VERSION);
        if ($version !== self::VERSION && $version !== '1.0') {
            // Layout oficial não carrega dto_version — trata como v1 se tiver campos SERPRO
            $looksOfficial = isset($body['periodos']) || isset($body['periodoApuracao'])
                || isset($body['operacoes']) || isset($body['numeroDeclaracao']);
            if (! $looksOfficial) {
                throw new InvalidArgumentException("PGDAS-D DTO versão não suportada: {$version}");
            }
            $version = self::VERSION;
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        // Contrato oficial: primeira declaração/operação quando disponível
        $firstOp = self::firstOfficialOperation($data);
        $status = strtoupper((string) ($data['status'] ?? $data['situacao'] ?? ($firstOp !== null ? 'ENTREGUE' : 'UNKNOWN')));
        if ($status === 'UNKNOWN' && isset($data['periodos']) && is_array($data['periodos']) && $data['periodos'] === []) {
            $status = 'PENDENTE';
        }

        $competence = (string) ($data['competence'] ?? $data['competencia'] ?? $fallbackCompetence);
        if ($competence === '' && isset($data['periodoApuracao'])) {
            $pa = preg_replace('/\D/', '', (string) $data['periodoApuracao']) ?? '';
            if (strlen($pa) === 6) {
                $competence = substr($pa, 0, 4).'-'.substr($pa, 4, 2);
            }
        }

        $receipt = isset($data['receipt_number'])
            ? (string) $data['receipt_number']
            : (isset($data['numero_recibo']) ? (string) $data['numero_recibo'] : null);
        $declId = isset($data['declaration_id'])
            ? (string) $data['declaration_id']
            : (isset($data['id_declaracao']) ? (string) $data['id_declaracao'] : null);
        if (($declId === null || $declId === '') && $firstOp !== null) {
            $declId = isset($firstOp['numeroDeclaracao']) ? (string) $firstOp['numeroDeclaracao'] : null;
        }
        $transmittedAt = isset($data['transmitted_at'])
            ? (string) $data['transmitted_at']
            : (isset($data['data_transmissao']) ? (string) $data['data_transmissao'] : null);
        if (($transmittedAt === null || $transmittedAt === '') && $firstOp !== null) {
            $transmittedAt = isset($firstOp['dataHoraTransmissao'])
                ? (string) $firstOp['dataHoraTransmissao']
                : null;
        }

        return new self(
            version: self::VERSION,
            competence: $competence,
            status: $status,
            receiptNumber: $receipt !== '' ? $receipt : null,
            declarationId: $declId !== '' ? $declId : null,
            transmittedAt: $transmittedAt !== '' ? $transmittedAt : null,
            situation: self::mapSituation($status, $receipt ?? $declId),
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function firstOfficialOperation(array $data): ?array
    {
        $ops = $data['operacoes'] ?? null;
        if (! is_array($ops) && isset($data['periodos']) && is_array($data['periodos'])) {
            foreach ($data['periodos'] as $period) {
                if (is_array($period) && isset($period['operacoes']) && is_array($period['operacoes']) && $period['operacoes'] !== []) {
                    $ops = $period['operacoes'];
                    break;
                }
            }
        }
        if (! is_array($ops) || $ops === []) {
            return null;
        }
        $first = $ops[0] ?? null;

        return is_array($first) ? $first : null;
    }

    private static function mapSituation(string $status, ?string $receipt): FiscalSituation
    {
        return match ($status) {
            'ENTREGUE', 'TRANSMITIDA', 'DELIVERED', 'OK', 'REGULAR' => $receipt !== null
                ? FiscalSituation::UpToDate
                : FiscalSituation::Unknown,
            'PENDENTE', 'PENDING', 'OMISSA', 'OMITIDA' => FiscalSituation::Pending,
            'PROCESSANDO', 'PROCESSING' => FiscalSituation::Processing,
            'ATENCAO', 'ATTENTION', 'DIVERGENTE' => FiscalSituation::Attention,
            'NAO_APLICAVEL', 'NOT_APPLICABLE' => FiscalSituation::NotApplicable,
            'ERRO', 'ERROR' => FiscalSituation::Error,
            'INCONCLUSIVO', 'UNKNOWN', '' => FiscalSituation::Unknown,
            default => FiscalSituation::Unknown,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        return [
            'dto' => 'pgdasd_declaration',
            'dto_version' => $this->version,
            'competence' => $this->competence,
            'status' => $this->status,
            'receipt_number' => $this->receiptNumber,
            'declaration_id' => $this->declarationId,
            'transmitted_at' => $this->transmittedAt,
            'situation' => $this->situation->value,
            'regime_family' => 'SIMPLES_NACIONAL',
        ];
    }
}
