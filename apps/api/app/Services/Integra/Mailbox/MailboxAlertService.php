<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\MailboxAlertSeverity;
use App\Models\MailboxAlert;
use App\Models\MailboxMessage;
use Carbon\CarbonImmutable;

/**
 * Alertas sanitizados: remetente/categoria/prazo/deep-link — sem corpo, anexo ou token.
 * Idempotente por mensagem.
 */
final class MailboxAlertService
{
    public function ensureForMessage(MailboxMessage $message): MailboxAlert
    {
        $existing = MailboxAlert::query()
            ->withoutGlobalScopes()
            ->where('office_id', $message->office_id)
            ->where('mailbox_message_id', $message->id)
            ->first();

        $payload = $this->buildSanitized($message);

        if ($existing !== null) {
            // Atualiza severidade/prazo se mudou; nunca grava corpo fiscal
            $existing->forceFill([
                'severity' => $payload['severity'],
                'title' => $payload['title'],
                'body' => $payload['body'],
                'deep_link' => $payload['deep_link'],
                'metadata' => $payload['metadata'],
            ])->save();

            return $existing->fresh();
        }

        return MailboxAlert::query()->create([
            'office_id' => $message->office_id,
            'client_id' => $message->client_id,
            'mailbox_message_id' => $message->id,
            'severity' => $payload['severity'],
            'title' => $payload['title'],
            'body' => $payload['body'],
            'deep_link' => $payload['deep_link'],
            'is_active' => true,
            'metadata' => $payload['metadata'],
        ]);
    }

    /**
     * @return array{severity:MailboxAlertSeverity,title:string,body:string,deep_link:string,metadata:array<string,mixed>}
     */
    public function buildSanitized(MailboxMessage $message): array
    {
        $dueSoonDays = (int) config('fiscal_monitoring.mailbox.due_soon_days', 7);
        $criticalCats = config('fiscal_monitoring.mailbox.critical_categories', []);
        if (! is_array($criticalCats)) {
            $criticalCats = [];
        }
        $criticalCats = array_map('strtoupper', $criticalCats);

        $dueSoon = false;
        if ($message->due_at !== null) {
            $dueSoon = $message->due_at->lessThanOrEqualTo(
                CarbonImmutable::now()->addDays($dueSoonDays)
            );
        }

        $catCode = strtoupper((string) ($message->category_code ?? ''));
        $criticalCategory = $catCode !== '' && in_array($catCode, $criticalCats, true);

        $severity = MailboxAlertSeverity::fromHint(
            $message->severity_hint,
            dueSoon: $dueSoon,
            criticalCategory: $criticalCategory,
        );

        $sender = $message->sender_label ?: ($message->sender_code ?: 'Remetente oficial');
        $category = $message->category_label ?: ($message->category_code ?: 'Mensagem');

        // Título/body: NUNCA copiar subject_preview, corpo ou nome de anexo
        $title = sprintf('Caixa Postal — %s', $this->clip($category, 80));
        $parts = [
            'Remetente: '.$this->clip($sender, 80),
        ];
        if ($message->due_at !== null) {
            $parts[] = 'Prazo: '.$message->due_at->toDateString();
            if ($dueSoon) {
                $parts[] = 'Prazo próximo';
            }
        }
        $parts[] = 'Abrir detalhe autorizado no MonitorHub.';
        $body = $this->clip(implode(' · ', $parts), 500) ?? 'Nova mensagem na Caixa Postal.';

        $deepLink = sprintf(
            '/fiscal/mailbox/messages/%d',
            $message->id
        );

        return [
            'severity' => $severity,
            'title' => $this->clip($title, 255) ?? 'Caixa Postal',
            'body' => $body,
            'deep_link' => $deepLink,
            'metadata' => [
                // Apenas metadados seguros
                'category_code' => $message->category_code,
                'sender_code' => $message->sender_code,
                'due_at' => $message->due_at?->toIso8601String(),
                'message_id' => $message->id,
                // Explicitamente sem subject/body/attachment
            ],
        ];
    }

    /**
     * Garante que texto de alerta/inbox não contém conteúdo fiscal conhecido.
     */
    public function assertSanitized(string $text, MailboxMessage $message): bool
    {
        $hay = mb_strtolower($text);
        if ($message->subject_preview !== null && $message->subject_preview !== '') {
            $needle = mb_strtolower($message->subject_preview);
            if (mb_strlen($needle) >= 8 && str_contains($hay, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
