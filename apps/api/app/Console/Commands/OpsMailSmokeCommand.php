<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Smoke SMTP — mensagem sem dado fiscal. Envio real é ops-gated (não roda em CI).
 */
class OpsMailSmokeCommand extends Command
{
    protected $signature = 'ops:mail-smoke
        {--to= : Destinatário do smoke (obrigatório)}
        {--json : Saída JSON sanitizada}';

    protected $description = 'Envia e-mail de smoke operacional (sem dado fiscal/tenant)';

    public function handle(): int
    {
        $to = trim((string) $this->option('to'));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Informe --to com e-mail válido.');

            return self::FAILURE;
        }

        $subject = 'KontiveHub — smoke SMTP operacional';
        $body = "Smoke SMTP da KontiveHub.\nSem dados fiscais, tenant ou credenciais.\nTimestamp UTC: "
            .now()->utc()->toIso8601String()."\n";

        try {
            Mail::raw($body, function ($message) use ($to, $subject): void {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            $payload = [
                'ok' => false,
                'sent' => false,
                'error' => 'send_failed',
            ];
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('Falha no envio SMTP (detalhe omitido).');
            }

            // Não vazar mensagem de exceção (pode conter host/credenciais).
            unset($e);

            return self::FAILURE;
        }

        $payload = [
            'ok' => true,
            'sent' => true,
            'to_domain' => $this->emailDomain($to),
            'mailer' => (string) config('mail.default'),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Smoke SMTP enfileirado/enviado (domínio destino: '.$payload['to_domain'].').');
        }

        return self::SUCCESS;
    }

    private function emailDomain(string $email): string
    {
        $parts = explode('@', $email, 2);

        return $parts[1] ?? 'unknown';
    }
}
