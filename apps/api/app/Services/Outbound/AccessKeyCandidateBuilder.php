<?php

namespace App\Services\Outbound;

use App\Enums\OutboundFiscalModel;
use RuntimeException;

/**
 * Builder versionado de chave candidata de 44 posições (modelos 55/65).
 * CNPJ/chave como texto maiúsculo; nunca inteiro.
 */
final class AccessKeyCandidateBuilder
{
    /**
     * @param  array{
     *   cuf?: string,
     *   aamm: string,
     *   cnpj: string,
     *   model: OutboundFiscalModel|string,
     *   series: int,
     *   nnf: int,
     *   tp_emis?: string,
     *   cnf?: string,
     * }  $parts
     * @return array{access_key: string, cnf: string, dv: string}
     */
    public function build(array $parts): array
    {
        $cuf = str_pad((string) ($parts['cuf'] ?? '21'), 2, '0', STR_PAD_LEFT);
        $aamm = preg_replace('/\D/', '', $parts['aamm']) ?? '';
        if (strlen($aamm) === 6) {
            // YYYYMM → AAMM
            $aamm = substr($aamm, 2, 4);
        }
        if (strlen($aamm) !== 4) {
            throw new RuntimeException('AAMM da chave candidata inválido.');
        }

        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $parts['cnpj']) ?? '');
        if (strlen($cnpj) !== 14) {
            throw new RuntimeException('CNPJ da chave candidata deve ter 14 caracteres.');
        }

        $model = $parts['model'] instanceof OutboundFiscalModel
            ? $parts['model']->value
            : (string) $parts['model'];
        $model = str_pad($model, 2, '0', STR_PAD_LEFT);

        $series = str_pad((string) (int) $parts['series'], 3, '0', STR_PAD_LEFT);
        $nnf = str_pad((string) (int) $parts['nnf'], 9, '0', STR_PAD_LEFT);
        $tpEmis = (string) ($parts['tp_emis'] ?? '1');
        if (strlen($tpEmis) !== 1) {
            $tpEmis = substr($tpEmis, -1);
        }

        $cnf = isset($parts['cnf']) && $parts['cnf'] !== ''
            ? str_pad(preg_replace('/\D/', '', (string) $parts['cnf']) ?? '0', 8, '0', STR_PAD_LEFT)
            : $this->deterministicCnf($cnpj, $model, $series, $nnf, $aamm);

        $body = $cuf.$aamm.$cnpj.$model.$series.$nnf.$tpEmis.$cnf;
        if (strlen($body) !== 43) {
            throw new RuntimeException('Corpo da chave candidata com tamanho inválido.');
        }

        $dv = (string) $this->mod11Dv($body);

        return [
            'access_key' => strtoupper($body.$dv),
            'cnf' => $cnf,
            'dv' => $dv,
        ];
    }

    /**
     * cNF determinístico e estável (não aleatório) para a mesma identidade.
     */
    public function deterministicCnf(string $cnpj, string $model, string $series, string $nnf, string $aamm): string
    {
        $seed = strtoupper($cnpj.'|'.$model.'|'.$series.'|'.$nnf.'|'.$aamm);
        $hash = hash('sha256', $seed);
        // 8 dígitos decimais a partir do hash
        $num = hexdec(substr($hash, 0, 8)) % 100000000;

        return str_pad((string) $num, 8, '0', STR_PAD_LEFT);
    }

    public function validateDv(string $accessKey): bool
    {
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? '');
        if (strlen($key) !== 44) {
            return false;
        }
        $body = substr($key, 0, 43);
        $dv = substr($key, 43, 1);

        return $dv === (string) $this->mod11Dv($body);
    }

    /**
     * Valida identidade da chave contra perfil/número.
     */
    public function matchesIdentity(
        string $accessKey,
        string $cuf,
        string $cnpj,
        string $model,
        int $series,
        int $nnf,
        string $tpEmis,
    ): bool {
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? '');
        if (strlen($key) < 44 || ! $this->validateDv($key)) {
            return false;
        }
        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cnpj) ?? '');
        $model = str_pad($model, 2, '0', STR_PAD_LEFT);

        return substr($key, 0, 2) === str_pad($cuf, 2, '0', STR_PAD_LEFT)
            && substr($key, 6, 14) === $cnpj
            && substr($key, 20, 2) === $model
            && (int) substr($key, 22, 3) === $series
            && (int) substr($key, 25, 9) === $nnf
            && substr($key, 34, 1) === $tpEmis;
    }

    private function mod11Dv(string $body): int
    {
        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;
        $w = 0;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $ch = $body[$i];
            $val = ctype_digit($ch) ? (int) $ch : (ord(strtoupper($ch)) - 55); // A=10 …
            if ($val < 0) {
                $val = 0;
            }
            $sum += $val * $weights[$w % 8];
            $w++;
        }
        $mod = $sum % 11;
        $dv = 11 - $mod;
        if ($dv >= 10) {
            $dv = 0;
        }

        return $dv;
    }
}
