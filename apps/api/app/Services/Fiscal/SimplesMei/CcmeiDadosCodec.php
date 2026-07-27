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
     * @return array{status:string,situation:FiscalSituation}
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
            'situation' => $this->situation($status),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function status(array $data): ?string
    {
        $value = $this->optionalString($data['situacaoCadastralVigente'] ?? null);

        return $value === null ? null : mb_strtoupper($value);
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
