<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\TaxRegimeCode;
use InvalidArgumentException;

/**
 * Regime de Apuração oficial (vigências SN/MEI).
 */
final readonly class RegimeApuracaoDto
{
    public const VERSION = '1';

    /**
     * @param  list<array{regime:string,effective_from:string,effective_to:?string}>  $periods
     */
    public function __construct(
        public string $version,
        public array $periods,
        public TaxRegimeCode $currentRegime,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body): self
    {
        $version = (string) ($body['dto_version'] ?? $body['version'] ?? self::VERSION);
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException("Regime Apuração DTO versão não suportada: {$version}");
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $rawPeriods = is_array($data['periods'] ?? null) ? $data['periods'] : (is_array($data['periodos'] ?? null) ? $data['periodos'] : []);

        $periods = [];
        foreach ($rawPeriods as $row) {
            if (! is_array($row)) {
                continue;
            }
            $regime = strtoupper((string) ($row['regime'] ?? $row['codigo_regime'] ?? 'UNKNOWN'));
            $from = (string) ($row['effective_from'] ?? $row['vigencia_inicio'] ?? '');
            $to = $row['effective_to'] ?? $row['vigencia_fim'] ?? null;
            if ($from === '') {
                continue;
            }
            $periods[] = [
                'regime' => self::normalizeRegime($regime)->value,
                'effective_from' => $from,
                'effective_to' => $to !== null && $to !== '' ? (string) $to : null,
            ];
        }

        $currentRaw = strtoupper((string) ($data['current_regime'] ?? $data['regime_atual'] ?? ''));
        if ($currentRaw === '' && $periods !== []) {
            $currentRaw = $periods[array_key_last($periods)]['regime'];
        }

        return new self(
            version: self::VERSION,
            periods: $periods,
            currentRegime: self::normalizeRegime($currentRaw !== '' ? $currentRaw : 'UNKNOWN'),
            raw: $data,
        );
    }

    public static function normalizeRegime(string $raw): TaxRegimeCode
    {
        $raw = strtoupper(trim($raw));

        return match ($raw) {
            'SN', 'SIMPLES', 'SIMPLES_NACIONAL', 'SIMPLES NACIONAL' => TaxRegimeCode::SimplesNacional,
            'MEI', 'SIMEI', 'MEI_SIMEI' => TaxRegimeCode::Mei,
            'OUTRO', 'LUCRO_PRESUMIDO', 'LUCRO_REAL', 'LP', 'LR' => TaxRegimeCode::Outro,
            default => TaxRegimeCode::Unknown,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        return [
            'dto' => 'regime_apuracao',
            'dto_version' => $this->version,
            'current_regime' => $this->currentRegime->value,
            'periods' => $this->periods,
        ];
    }
}
