<?php

namespace App\Services\Operations\Inbox;

use App\Models\MailboxAlert;
use Illuminate\Support\Collection;

/**
 * Alertas de Caixa Postal — título/body sanitizados (sem corpo, anexo ou assunto fiscal).
 */
final class MailboxItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $role): Collection
    {
        $rows = MailboxAlert::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        return collect($rows->map(function (MailboxAlert $alert) {
            $sev = $alert->severity?->value ?? 'medium';
            $type = in_array($sev, ['critical', 'high'], true)
                ? 'mailbox_message_urgent'
                : 'mailbox_message';

            $subject = implode(':', ['mb', (string) $alert->id, $type]);
            $id = substr(hash('sha256', $subject), 0, 32);

            return [
                'id' => $id,
                'type' => $type,
                'severity' => InboxItemFactory::TYPE_SEVERITY[$type] ?? $sev,
                'title' => $this->items->sanitizeText($alert->title) ?? 'Caixa Postal',
                'body' => $this->items->sanitizeText($alert->body) ?? 'Nova mensagem. Abrir detalhe autorizado.',
                'reasons' => ['mailbox', 'category_meta_only'],
                'client_id' => $alert->client_id,
                'establishment_id' => null,
                'occurred_at' => $alert->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'links' => [
                    'mailbox' => $alert->deep_link,
                ],
                'actions' => [
                    ['type' => 'open', 'label' => 'Abrir mensagem', 'message_id' => $alert->mailbox_message_id],
                ],
            ];
        })->values()->all());
    }
}
