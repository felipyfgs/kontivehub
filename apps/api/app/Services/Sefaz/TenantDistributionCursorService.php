<?php

namespace App\Services\Sefaz;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Enums\TenantFiscalIdentityStatus;
use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use App\Support\AutXmlFeature;
use RuntimeException;

/**
 * Cursor central do escritório: tenant_id + cnpj_base + environment + channel.
 * Proíbe cursores paralelos da mesma raiz.
 */
final class TenantDistributionCursorService
{
    public function environment(): string
    {
        return (string) config('sefaz.environment', 'production');
    }

    public function ensureForIdentity(
        TenantFiscalIdentity $identity,
        ?string $environment = null,
    ): TenantDistributionCursor {
        if ($identity->status !== TenantFiscalIdentityStatus::Active) {
            throw new RuntimeException('Identidade fiscal inativa — cursor autXML não pode ser criado.');
        }

        $env = $environment ?? $this->environment();
        $channel = CaptureChannel::NfeAutXmlDistDfe;

        return TenantDistributionCursor::query()->firstOrCreate(
            [
                'tenant_id' => $identity->tenant_id,
                'interested_root_cnpj' => $identity->root_cnpj,
                'environment' => $env,
                'channel' => $channel->value,
            ],
            [
                'tenant_fiscal_identity_id' => $identity->id,
                'query_cnpj' => $identity->cnpj,
                'last_nsu' => 0,
                'status' => SyncCursorStatus::Idle,
                'next_sync_at' => now(),
                'external_consumer_status' => null, // gate até declaração
            ]
        );
    }

    /**
     * @return list<TenantDistributionCursor>
     */
    public function dueCursors(int $limit = 50): array
    {
        if (! AutXmlFeature::isGloballyEnabled()) {
            return [];
        }

        $q = TenantDistributionCursor::query()
            ->where('channel', CaptureChannel::NfeAutXmlDistDfe->value)
            ->whereIn('status', [
                SyncCursorStatus::Idle->value,
                SyncCursorStatus::Error->value,
            ])
            ->where(function ($q): void {
                $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now());
            })
            // Conflito de consumidor externo: job no-op — não enfileirar (paridade CT-e).
            ->where(function ($q): void {
                $q->whereNull('external_consumer_status')
                    ->orWhere('external_consumer_status', '!=', 'EXTERNAL_CONSUMER_CONFLICT');
            })
            ->orderBy('next_sync_at')
            ->limit($limit)
            ->get();

        return $q->filter(fn (TenantDistributionCursor $c) => AutXmlFeature::isTenantAllowed((int) $c->tenant_id))
            ->values()
            ->all();
    }

    public function markActivated(TenantDistributionCursor $cursor): void
    {
        if ($cursor->activated_at === null) {
            $cursor->activated_at = now();
            $cursor->save();
        }
    }
}
