<?php

namespace App\Services\Operations\Inbox;

use App\Enums\CredentialStatus;
use App\Models\ClientCredential;
use App\Models\InstanceBackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Credenciais A1 e status de backup da instância.
 */
final class CredentialBackupItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $role): Collection
    {
        return $this->credentialItems($officeId)
            ->merge($this->backupItems())
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function credentialItems(int $officeId): Collection
    {
        $now = CarbonImmutable::now();
        $credentials = ClientCredential::query()
            ->where('office_id', $officeId)
            ->whereIn('status', [CredentialStatus::Active, CredentialStatus::Expired])
            ->with('client')
            ->orderBy('id')
            ->get();

        $items = collect();

        foreach ($credentials as $credential) {
            $client = $credential->client;
            if ($client === null) {
                continue;
            }

            $validTo = $credential->valid_to;
            $expired = $credential->status === CredentialStatus::Expired
                || ($validTo !== null && $validTo->isPast());

            if ($expired) {
                $items->push($this->items->item(
                    type: 'credential_expired',
                    title: 'Certificado A1 vencido: '.$this->items->clientLabel($client),
                    body: 'A credencial ACTIVE/operacional está vencida. Atualize o certificado do cliente.',
                    reasons: ['credential_expired'],
                    clientId: $client->id,
                    establishmentId: null,
                    occurredAt: $validTo?->toIso8601String() ?? $credential->updated_at?->toIso8601String() ?? $now->toIso8601String(),
                    role: null,
                    establishment: null,
                    cursor: null,
                ));

                continue;
            }

            if ($credential->status !== CredentialStatus::Active || $validTo === null) {
                continue;
            }

            // Alinhado a CredentialService (floor de floatDiffInRealDays).
            $days = (int) floor($now->floatDiffInRealDays($validTo, false));
            if ($days < 0) {
                continue;
            }

            if ($credential->expires_alert_1 || $credential->expires_alert_7 || $days <= 7) {
                $items->push($this->items->item(
                    type: 'credential_expiring_7d',
                    title: 'Certificado A1 vence em breve: '.$this->items->clientLabel($client),
                    body: 'Vencimento em até 7 dias ('.$validTo->toDateString().').',
                    reasons: ['credential_expiring_7d'],
                    clientId: $client->id,
                    establishmentId: null,
                    occurredAt: $validTo->toIso8601String(),
                    role: null,
                    establishment: null,
                    cursor: null,
                ));

                continue;
            }

            if ($credential->expires_alert_30 || $days <= 30) {
                $items->push($this->items->item(
                    type: 'credential_expiring_30d',
                    title: 'Certificado A1 a vencer: '.$this->items->clientLabel($client),
                    body: 'Vencimento em até 30 dias ('.$validTo->toDateString().').',
                    reasons: ['credential_expiring_30d'],
                    clientId: $client->id,
                    establishmentId: null,
                    occurredAt: $validTo->toIso8601String(),
                    role: null,
                    establishment: null,
                    cursor: null,
                ));
            }
        }

        return $items->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function backupItems(): Collection
    {
        $summary = InstanceBackupRun::statusSummary();
        $items = collect();

        if ($summary['never']) {
            $items->push($this->items->item(
                type: 'backup_never',
                title: 'Nenhum backup bem-sucedido registrado',
                body: 'A instância ainda não possui backup SUCCESS. Execute o backup operacional e o restore drill antes do piloto com dados reais.',
                reasons: ['backup_never'],
                clientId: null,
                establishmentId: null,
                occurredAt: now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        } elseif ($summary['stale']) {
            $items->push($this->items->item(
                type: 'backup_stale',
                title: 'Backup da instância atrasado',
                body: 'Não há backup SUCCESS nas últimas 24 horas.'
                    .($summary['last_success_at'] ? ' Último sucesso: '.$summary['last_success_at'].'.' : ''),
                reasons: ['backup_stale'],
                clientId: null,
                establishmentId: null,
                occurredAt: $summary['last_success_at'] ?? now()->toIso8601String(),
                role: null,
                establishment: null,
                cursor: null,
            ));
        }

        return $items->values();
    }
}
