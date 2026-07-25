<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use InvalidArgumentException;

/**
 * Normaliza a resposta de CCMEI sem propagar CPF, endereço, CNPJ ou QR code.
 *
 * A resposta oficial de DADOSCCMEI122 contém dados pessoais e um QR code
 * Base64. Este codec é deliberadamente uma allowlist: campos não previstos
 * nunca alcançam evidência, projeção, API ou logs.
 */
final class CcmeiDadosCodec
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:string,certificate_number:?string,issued_at:?string,situation:FiscalSituation}
     */
    public function decode(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $status = $this->status($data);

        if ($status === null) {
            throw new InvalidArgumentException('Resposta CCMEI inválida ou ambígua.');
        }

        return [
            'status' => $status,
            // Compatibilidade com fixtures simuladas; esses campos não vêm da
            // resposta oficial e são mantidos somente quando explicitamente
            // presentes, sem reter o restante do payload.
            'certificate_number' => $this->optionalString($data['certificate_number'] ?? $data['numero_certificado'] ?? null),
            'issued_at' => $this->optionalString($data['issued_at'] ?? $data['data_emissao'] ?? null),
            'situation' => $this->situation($status),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function status(array $data): ?string
    {
        foreach (['situacaoCadastralVigente', 'situacao_cadastral_vigente', 'status', 'situacao'] as $field) {
            $value = $this->optionalString($data[$field] ?? null);
            if ($value !== null) {
                return mb_strtoupper($value);
            }
        }

        return null;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function situation(string $status): FiscalSituation
    {
        return match ($status) {
            'ATIVO', 'ATIVA', 'VALIDO', 'VÁLIDO', 'OK', 'EMITIDO' => FiscalSituation::UpToDate,
            'INATIVO', 'INATIVA', 'CANCELADO', 'CANCELADA', 'SUSPENSO', 'SUSPENSA' => FiscalSituation::Attention,
            default => FiscalSituation::Unknown,
        };
    }
}
