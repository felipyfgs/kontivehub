<?php

namespace App\Services\Fiscal\Demo;

/**
 * Conteúdo demonstrativo inofensivo com marca d'água obrigatória.
 * Nunca gera XML fiscal real, PFX, PEM, tokens ou material criptográfico.
 */
final class DemoContentFactory
{
    public function __construct(
        private readonly DemoEnvironmentGuard $guard,
    ) {}

    public function watermark(): string
    {
        return $this->guard->watermark();
    }

    /**
     * JSON sintético de evidência/relatório (SITFIS, DCTFWeb, etc.).
     *
     * @param  array<string, mixed>  $payload
     */
    public function evidenceJson(string $logicalKey, array $payload = []): string
    {
        $body = array_merge([
            'disclaimer' => $this->watermark(),
            'origin' => 'DEMO_FIXTURE',
            'logical_key' => $logicalKey,
            'simulated' => true,
            'valid_fiscal' => false,
        ], $payload);

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
    }

    /** Corpo textual de mensagem de Caixa Postal. */
    public function mailboxBody(string $subject, string $logicalKey): string
    {
        $w = $this->watermark();

        return <<<TXT
{$w}

Assunto: {$subject}
Chave lógica: {$logicalKey}

Este conteúdo é integralmente sintético e não constitui intimação, notificação
ou qualquer comunicação oficial da RFB/SEFAZ. Uso exclusivo em ambiente de
demonstração local/testing.
TXT;
    }

    /** Anexo textual sintético. */
    public function attachmentBytes(string $filename, string $logicalKey): string
    {
        $w = $this->watermark();

        return "{$w}\nArquivo: {$filename}\nChave: {$logicalKey}\nSimulado — sem valor fiscal.\n";
    }

    /** PDF-like bytes (não é PDF real; content-type application/pdf apenas para fluxo de download). */
    public function guideDocumentBytes(string $documentNumber, string $logicalKey, int $amountCents): string
    {
        $w = $this->watermark();
        $amount = number_format($amountCents / 100, 2, ',', '.');

        return "{$w}\nGUIA SIMULADA {$documentNumber}\nValor: R$ {$amount}\nChave: {$logicalKey}\n";
    }

    /**
     * Metadados padrão para registros demo (sem IDs de cofre).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function metadata(string $logicalKey, array $extra = []): array
    {
        return array_merge([
            'demo_fixture' => true,
            'origin' => 'DEMO_FIXTURE',
            'simulated' => true,
            'logical_key' => $logicalKey,
            'manifest_version' => $this->guard->manifestVersion(),
            'watermark' => $this->watermark(),
        ], $extra);
    }
}
