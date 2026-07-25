<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdDocumentKind;
use InvalidArgumentException;
use RuntimeException;

/** Codecs estritos dos serviços documentais PGDAS-D 14, 15 e 16. */
final class PgdasdDocumentCodecs
{
    /** @return array{periodoApuracao:string} */
    public function buildPayload14(string $periodoApuracao): array
    {
        $pa = trim($periodoApuracao);
        if (! $this->validPeriodoApuracao($pa)) {
            throw new InvalidArgumentException('CONSULTIMADECREC14 exige periodoApuracao AAAAMM.');
        }

        return ['periodoApuracao' => $pa];
    }

    /** @return array{numeroDeclaracao:string} */
    public function buildPayload15(string $numeroDeclaracao): array
    {
        $number = trim($numeroDeclaracao);
        if ($number === '' || mb_strlen($number) > 17) {
            throw new InvalidArgumentException('CONSDECREC15 exige numeroDeclaracao (até 17 caracteres).');
        }

        return ['numeroDeclaracao' => $number];
    }

    /** @return array{numeroDas:string} */
    public function buildPayload16(string $numeroDas): array
    {
        $number = trim($numeroDas);
        if ($number === '' || mb_strlen($number) > 17) {
            throw new InvalidArgumentException('CONSEXTRATO16 exige numeroDas (até 17 caracteres).');
        }

        return ['numeroDas' => $number];
    }

    /**
     * @return list<array{
     *   kind:PgdasdDocumentKind,
     *   base64:string,
     *   filename_hint:?string,
     *   numero_declaracao:?string,
     *   numero_das:?string,
     *   path:string
     * }>
     */
    public function extractDocumentFields(mixed $dados, string $operationKey): array
    {
        $root = $this->coerceArray($dados);
        $declarationNumber = $this->stringOrNull($root['numeroDeclaracao'] ?? null);
        $dasNumber = $this->stringOrNull($root['numeroDas'] ?? null);

        $paths = match ($operationKey) {
            'pgdasd.consultimadecrec', 'pgdasd.consdecrec' => [
                ['declaracao.pdf', PgdasdDocumentKind::Declaracao, 'declaracao.nomeArquivo'],
                ['recibo.pdf', PgdasdDocumentKind::Recibo, 'recibo.nomeArquivo'],
                ['maed.pdfNotificacao', PgdasdDocumentKind::NotificacaoMaed, 'maed.nomeArquivoNotificacao'],
                ['maed.pdfDarf', PgdasdDocumentKind::DarfMaed, 'maed.nomeArquivoDarf'],
            ],
            'pgdasd.consextrato' => [
                ['extrato.pdf', PgdasdDocumentKind::Extrato, 'extrato.nomeArquivo'],
            ],
            default => throw new InvalidArgumentException('Operação documental PGDAS-D não suportada.'),
        };

        $documents = [];
        foreach ($paths as [$path, $kind, $filenamePath]) {
            $base64 = $this->valueAtPath($root, $path);
            if (! is_string($base64) || trim($base64) === '') {
                continue;
            }

            $filename = $this->valueAtPath($root, $filenamePath);
            $documents[] = [
                'kind' => $kind,
                'base64' => trim($base64),
                'filename_hint' => is_string($filename) && trim($filename) !== '' ? trim($filename) : null,
                'numero_declaracao' => $declarationNumber,
                'numero_das' => $dasNumber,
                'path' => $path,
            ];
        }

        return $documents;
    }

    /**
     * Substitui todo campo binário oficial pelo descritor da mesma posição.
     *
     * @param  array<string, array<string, mixed>>  $descriptorsByPath
     * @return array<string, mixed>
     */
    public function sanitizeDados(mixed $dados, array $descriptorsByPath): array
    {
        $root = $this->coerceArray($dados);
        $binaryNames = ['pdf', 'pdfNotificacao', 'pdfDarf'];

        $walk = function (array &$node, string $prefix = '') use (&$walk, $binaryNames, $descriptorsByPath): void {
            foreach ($node as $key => &$value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (in_array((string) $key, $binaryNames, true) && is_string($value)) {
                    $value = $descriptorsByPath[$path] ?? [
                        'sanitized' => true,
                        'available' => false,
                        'reason' => 'DOCUMENT_NOT_STORED',
                    ];

                    continue;
                }
                if (is_array($value)) {
                    $walk($value, $path);
                }
            }
            unset($value);
        };

        $walk($root);

        return $root;
    }

    /** @return array<string, mixed> */
    private function coerceArray(mixed $dados): array
    {
        if ($dados === null || $dados === '') {
            throw new RuntimeException('Resposta documental sem dados.');
        }
        if (is_string($dados)) {
            $decoded = json_decode($dados, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Resposta documental: dados não é JSON de objeto.');
            }

            return $decoded;
        }
        if (! is_array($dados)) {
            throw new RuntimeException('Resposta documental: dados em formato inválido.');
        }

        return $dados;
    }

    private function valueAtPath(array $root, string $path): mixed
    {
        $value = $root;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function validPeriodoApuracao(string $value): bool
    {
        if (preg_match('/^\d{6}$/', $value) !== 1) {
            return false;
        }

        $month = (int) substr($value, 4, 2);

        return $month >= 1 && $month <= 12;
    }
}
