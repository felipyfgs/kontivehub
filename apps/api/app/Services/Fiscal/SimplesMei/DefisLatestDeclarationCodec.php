<?php

namespace App\Services\Fiscal\SimplesMei;

use InvalidArgumentException;

/** Codec fail-closed de DEFIS / CONSULTIMADECREC143, sem reter idDefis. */
final class DefisLatestDeclarationCodec
{
    public const MAX_PDF_BYTES = 5_242_880;

    public function assertCalendarYear(mixed $year): int
    {
        if (! is_int($year) && ! (is_string($year) && preg_match('/^\d{4}$/', $year) === 1)) {
            throw new InvalidArgumentException('Ano-calendário DEFIS inválido.');
        }

        $value = (int) $year;
        if ($value < 2000 || $value > 2100) {
            throw new InvalidArgumentException('Ano-calendário DEFIS inválido.');
        }

        return $value;
    }

    /**
     * @return array{calendar_year:int,documents:list<array{kind:string,bytes:string}>}
     */
    public function decode(mixed $dados, int $expectedYear): array
    {
        $root = is_string($dados) ? json_decode($dados, true) : $dados;
        if (is_array($root) && array_is_list($root)) {
            $root = $root[0] ?? null;
        }
        if (! is_array($root)) {
            throw new InvalidArgumentException('Resposta DEFIS 143 inválida.');
        }

        $year = array_key_exists('ano', $root) ? $this->assertCalendarYear($root['ano']) : $expectedYear;
        if ($year !== $expectedYear) {
            throw new InvalidArgumentException('Resposta DEFIS 143 inválida.');
        }

        return [
            'calendar_year' => $year,
            'documents' => [
                ['kind' => 'RECIBO', 'bytes' => $this->decodePdf($root['recibo'] ?? null)],
                ['kind' => 'DECLARACAO', 'bytes' => $this->decodePdf($root['declaracao'] ?? null)],
            ],
        ];
    }

    private function decodePdf(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Resposta DEFIS 143 inválida.');
        }
        $base64 = preg_replace('/\s+/', '', $value) ?? '';
        if ($base64 === '' || strlen($base64) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $base64) !== 1) {
            throw new InvalidArgumentException('Resposta DEFIS 143 inválida.');
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || base64_encode($bytes) !== $base64 || strlen($bytes) > self::MAX_PDF_BYTES || ! str_starts_with($bytes, '%PDF-')) {
            throw new InvalidArgumentException('Resposta DEFIS 143 inválida.');
        }

        return $bytes;
    }
}
