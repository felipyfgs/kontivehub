<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extrator controlado de texto via pdftotext (poppler-utils).
 * Entrada máx. 10 MiB, saída máx. 2 MiB, timeout 15s.
 * Texto intermediário NÃO é persistido pelo chamador.
 */
final class PdfTextExtractor
{
    public const MAX_INPUT_BYTES = 10 * 1024 * 1024;

    public const MAX_OUTPUT_BYTES = 2 * 1024 * 1024;

    public const TIMEOUT_SECONDS = 15.0;

    public function __construct(
        private readonly string $binary = '/usr/bin/pdftotext',
    ) {}

    public function isAvailable(): bool
    {
        return is_executable($this->binary);
    }

    /**
     * @throws RuntimeException quando indisponível, timeout, limite ou falha do processo
     */
    public function extract(string $pdfBytes): string
    {
        $size = strlen($pdfBytes);
        if ($size === 0) {
            throw new RuntimeException('PDF vazio para extração de texto.');
        }
        if ($size > self::MAX_INPUT_BYTES) {
            throw new RuntimeException(
                'PDF excede limite de entrada de '.self::MAX_INPUT_BYTES.' bytes para pdftotext.'
            );
        }
        if (! str_starts_with($pdfBytes, '%PDF')) {
            throw new RuntimeException('Conteúdo não é PDF válido (assinatura %PDF ausente).');
        }
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'pdftotext indisponível (poppler-utils). Caminho esperado: '.$this->binary
            );
        }

        $process = new Process([
            $this->binary,
            '-layout',
            '-enc',
            'UTF-8',
            '-nopgbrk',
            '-',
            '-',
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->setInput($pdfBytes);
        $text = '';
        $stderr = '';

        try {
            $process->run(function (string $type, string $buffer) use (&$text, &$stderr, $process): void {
                if ($type === Process::OUT) {
                    $text .= $buffer;
                    if (strlen($text) > self::MAX_OUTPUT_BYTES) {
                        $process->stop(0);
                        throw new RuntimeException(
                            'Saída de pdftotext excede limite de '.self::MAX_OUTPUT_BYTES.' bytes.'
                        );
                    }

                    return;
                }

                $stderr .= $buffer;
                if (strlen($stderr) > 4096) {
                    $stderr = substr($stderr, 0, 4096);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('pgdasd.pdf_text.extract_failed', [
                'reason' => $e instanceof RuntimeException ? $e->getMessage() : 'PROCESS_FAILURE',
                'input_bytes' => $size,
            ]);

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException('Falha ao executar pdftotext.', previous: $e);
        }

        if (! $process->isSuccessful()) {
            $err = trim($stderr !== '' ? $stderr : $process->getErrorOutput());
            $err = mb_substr($err, 0, 500);
            throw new RuntimeException(
                'pdftotext falhou'.($err !== '' ? ': '.$err : ' (exit '.$process->getExitCode().').')
            );
        }

        return $text;
    }
}
