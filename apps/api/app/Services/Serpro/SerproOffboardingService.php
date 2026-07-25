<?php

namespace App\Services\Serpro;

use App\Contracts\SecureObjectStore;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproRetentionJob;
use App\Models\TaxProxyPower;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revogação imediata + GC seguro após prazo legal de material SERPRO tenant.
 * Ledger e auditoria NÃO são apagados antes da retenção configurada.
 */
final class SerproOffboardingService
{
    public const CATEGORY_PFX = 'PFX';

    public const CATEGORY_TOKEN = 'TOKEN';

    public const CATEGORY_TERMO = 'TERMO';

    public const CATEGORY_POWER = 'POWER';

    public const CATEGORY_EVIDENCE = 'EVIDENCE';

    public const CATEGORY_LEDGER = 'LEDGER';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecureObjectStore $store,
    ) {}

    /**
     * @return list<SerproRetentionJob>
     */
    public function revokeOffice(Office $office, string $reason, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($office, $reason, $actorUserId): array {
            $jobs = [];
            $now = CarbonImmutable::now();

            // 1) Revoga tokens/Termo/A1 imediatamente (metadados + vault refs)
            $auths = OfficeSerproAuthorization::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->get();

            $tokenRefs = 0;
            $termoRefs = 0;
            $pfxRefs = 0;

            foreach ($auths as $auth) {
                if ($auth->procurador_token_vault_object_id) {
                    $tokenRefs++;
                    $this->safeDeleteVault($auth->procurador_token_vault_object_id);
                }
                if ($auth->termo_vault_object_id) {
                    $termoRefs++;
                    // Termo: revoga uso (null token/etag) mas agenda purge do XML após retenção
                }
                if ($auth->author_pfx_vault_object_id) {
                    $pfxRefs++;
                }

                $auth->forceFill([
                    'procurador_token_vault_object_id' => null,
                    'procurador_token_expires_at' => null,
                    'procurador_etag' => null,
                    'last_token_refresh_at' => null,
                    'status' => SerproAuthorizationStatus::Revoked,
                    'action_required_reason' => 'OFFBOARDING: '.mb_substr($reason, 0, 400),
                    'metadata' => array_merge(is_array($auth->metadata) ? $auth->metadata : [], [
                        'offboarded_at' => $now->toIso8601String(),
                    ]),
                ])->save();
            }

            // 2) Encerra poderes
            $powersClosed = TaxProxyPower::query()
                ->where('office_id', $office->id)
                ->whereIn('status', [
                    TaxProxyPowerStatus::Active->value,
                    TaxProxyPowerStatus::Pending->value,
                    TaxProxyPowerStatus::Insufficient->value,
                ])
                ->update([
                    'status' => TaxProxyPowerStatus::Revoked->value,
                    'closed_at' => $now,
                    'updated_at' => $now,
                ]);

            $retention = $this->retentionDays();

            $jobs[] = $this->enqueueJob($office->id, self::CATEGORY_TOKEN, $reason, $actorUserId, $now, 0, [
                'revoked_refs' => $tokenRefs,
            ]);
            $jobs[] = $this->enqueueJob($office->id, self::CATEGORY_TERMO, $reason, $actorUserId, $now, $retention['termo'], [
                'pending_vault_purge' => $termoRefs,
            ]);
            $jobs[] = $this->enqueueJob($office->id, self::CATEGORY_PFX, $reason, $actorUserId, $now, $retention['pfx'], [
                'pending_vault_purge' => $pfxRefs,
            ]);
            $jobs[] = $this->enqueueJob($office->id, self::CATEGORY_POWER, $reason, $actorUserId, $now, $retention['power'], [
                'closed' => $powersClosed,
            ]);
            $jobs[] = $this->enqueueJob($office->id, self::CATEGORY_EVIDENCE, $reason, $actorUserId, $now, $retention['evidence'], []);
            $jobs[] = $this->enqueueJob($office->id, self::CATEGORY_LEDGER, $reason, $actorUserId, $now, $retention['ledger'], [
                'note' => 'Ledger preservado até prazo legal; GC apenas após eligible_purge_at.',
            ]);

            $this->audit->record('serpro.offboarding.revoke', 'SUCCESS', $office, [
                'reason' => mb_substr($reason, 0, 200),
                'token_refs' => $tokenRefs,
                'termo_refs' => $termoRefs,
                'pfx_refs' => $pfxRefs,
                'powers_closed' => $powersClosed,
            ], $actorUserId, $office->id);

            return $jobs;
        });
    }

    /**
     * GC seguro de jobs elegíveis (após prazo legal). Não apaga auditoria.
     *
     * @return array{purged: int, skipped: int}
     */
    public function runSafeGc(?int $limit = 50): array
    {
        if (! Schema::hasTable('serpro_retention_jobs')) {
            return ['purged' => 0, 'skipped' => 0];
        }

        $now = CarbonImmutable::now();
        $jobs = SerproRetentionJob::query()
            ->where('status', 'PENDING')
            ->whereNotNull('eligible_purge_at')
            ->where('eligible_purge_at', '<=', $now)
            ->where('category', '!=', self::CATEGORY_LEDGER) // ledger: política separada
            ->orderBy('id')
            ->limit(max(1, $limit ?? 50))
            ->get();

        $purged = 0;
        $skipped = 0;

        foreach ($jobs as $job) {
            try {
                $this->purgeJob($job);
                $purged++;
            } catch (\Throwable $e) {
                $skipped++;
                report($e);
            }
        }

        return ['purged' => $purged, 'skipped' => $skipped];
    }

    /**
     * @return array{pfx: int, token: int, termo: int, power: int, evidence: int, ledger: int}
     */
    public function retentionDays(): array
    {
        $cfg = config('serpro.retention', []);

        return [
            'pfx' => (int) ($cfg['pfx_days'] ?? 2555),
            'token' => (int) ($cfg['token_days'] ?? 0), // tokens revogados: purge imediato já feito
            'termo' => (int) ($cfg['termo_days'] ?? 2555),
            'power' => (int) ($cfg['power_days'] ?? 2555),
            'evidence' => (int) ($cfg['evidence_days'] ?? 2555),
            'ledger' => (int) ($cfg['ledger_days'] ?? config('serpro_usage.ledger_retention_days', 2555)),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function enqueueJob(
        int $officeId,
        string $category,
        string $reason,
        ?int $actorUserId,
        CarbonImmutable $now,
        int $retentionDays,
        array $summary,
    ): SerproRetentionJob {
        return SerproRetentionJob::query()->create([
            'office_id' => $officeId,
            'category' => $category,
            'status' => $category === self::CATEGORY_TOKEN ? 'PURGED' : 'PENDING',
            'trigger' => 'OFFBOARDING',
            'revoked_at' => $now,
            'eligible_purge_at' => $now->addDays(max(0, $retentionDays)),
            'purged_at' => $category === self::CATEGORY_TOKEN ? $now : null,
            'requested_by_user_id' => $actorUserId,
            'reason' => mb_substr($reason, 0, 500),
            'summary' => $summary,
        ]);
    }

    private function purgeJob(SerproRetentionJob $job): void
    {
        if ($job->category === self::CATEGORY_TERMO || $job->category === self::CATEGORY_PFX) {
            $auths = OfficeSerproAuthorization::query()
                ->where('office_id', $job->office_id)
                ->get();

            foreach ($auths as $auth) {
                if ($job->category === self::CATEGORY_TERMO && $auth->termo_vault_object_id) {
                    $this->safeDeleteVault($auth->termo_vault_object_id);
                    $auth->forceFill(['termo_vault_object_id' => null])->save();
                }
                if ($job->category === self::CATEGORY_PFX && $auth->author_pfx_vault_object_id) {
                    $this->safeDeleteVault($auth->author_pfx_vault_object_id);
                    $auth->forceFill([
                        'author_pfx_vault_object_id' => null,
                        'author_fingerprint_sha256' => null,
                    ])->save();
                }
            }
        }

        $job->forceFill([
            'status' => 'PURGED',
            'purged_at' => now(),
        ])->save();

        $this->audit->record('serpro.retention.purge', 'SUCCESS', $job, [
            'category' => $job->category,
            'office_id' => $job->office_id,
        ], null, $job->office_id);
    }

    private function safeDeleteVault(?string $objectId): void
    {
        if ($objectId === null || $objectId === '') {
            return;
        }

        try {
            if (method_exists($this->store, 'delete')) {
                $this->store->delete($objectId);
            }
        } catch (\Throwable) {
            // GC best-effort; vault órfão é recolhido por journal
        }
    }
}
