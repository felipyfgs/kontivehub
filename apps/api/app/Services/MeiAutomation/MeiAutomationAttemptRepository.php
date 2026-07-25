<?php

namespace App\Services\MeiAutomation;

use App\DTO\MeiAutomation\MeiAutomationJobResult;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationKind;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\MeiAutomationAttempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class MeiAutomationAttemptRepository
{
    public function __construct(
        private readonly MeiAutomationMetadataSanitizer $sanitizer,
        private readonly MeiPortalResultValidator $results,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createOrGet(int $officeId, string $idempotencyKey, int $attemptNumber, array $attributes): MeiAutomationAttempt
    {
        $attempt = MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('attempt_number', $attemptNumber)
            ->first();

        if ($attempt !== null) {
            if (! hash_equals((string) $attempt->request_fingerprint, (string) ($attributes['request_fingerprint'] ?? ''))) {
                throw new LogicException('Idempotência MEI reutilizada com conteúdo diferente.');
            }

            return $attempt;
        }

        return MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            ...$attributes,
            'office_id' => $officeId,
            'idempotency_key' => $idempotencyKey,
            'attempt_number' => $attemptNumber,
            'safe_metadata' => $this->sanitizer->sanitize((array) ($attributes['safe_metadata'] ?? [])),
        ]);
    }

    public function findForOffice(int $officeId, int $attemptId): MeiAutomationAttempt
    {
        return MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->findOrFail($attemptId);
    }

    public function findByExternalJobForOffice(int $officeId, string $externalJobId): MeiAutomationAttempt
    {
        $attempt = MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('external_job_id', $externalJobId)
            ->first();

        if ($attempt === null) {
            throw (new ModelNotFoundException)->setModel(MeiAutomationAttempt::class, [$externalJobId]);
        }

        return $attempt;
    }

    /** @param array<string, mixed> $metadata */
    public function synchronize(MeiAutomationAttempt $attempt, MeiAutomationJobResult $job, array $metadata = []): MeiAutomationAttempt
    {
        $errorCode = is_string($job->error['code'] ?? null) ? $job->error['code'] : null;
        $errorMessage = is_string($job->error['message'] ?? null) ? $job->error['message'] : null;
        $provider = $attempt->provider;
        $submitted = ($job->error['submitted'] ?? false) === true
            || ($job->result['submitted'] ?? false) === true;
        $result = $this->results->validate((string) $attempt->operation_key, $job->result);
        $safeResultMetadata = is_array($result) ? array_filter([
            'coverage' => $result['coverage'] ?? null,
            'parser_version' => $result['parser_version'] ?? null,
            'portal_version' => $result['portal_version'] ?? null,
        ], static fn (mixed $value): bool => $value !== null) : [];
        $safeMetadata = $this->sanitizer->sanitize([
            ...(array) ($attempt->safe_metadata ?? []),
            ...$metadata,
            ...$safeResultMetadata,
            'action_type' => $job->actionType,
            'artifact_count' => count($job->artifacts),
        ]);

        $attempt->forceFill([
            'external_job_id' => $job->id,
            'status' => $job->status,
            'source_provenance' => $provider === MeiProvider::ReceitaPortal
                ? FiscalSourceProvenance::ReceitaPortal
                : ($provider === MeiProvider::Serpro ? FiscalSourceProvenance::SerproReal : null),
            'verification_kind' => $provider === MeiProvider::ReceitaPortal
                ? FiscalVerificationKind::PortalArtifact
                : ($provider === MeiProvider::Serpro ? FiscalVerificationKind::SerproApi : null),
            'error_code' => $errorCode === null ? null : mb_substr($errorCode, 0, 80),
            'error_message' => $this->sanitizer->error($errorMessage),
            'portal_version' => $safeMetadata['portal_version'] ?? $attempt->portal_version,
            'parser_version' => $safeMetadata['parser_version'] ?? $attempt->parser_version,
            'captcha_driver' => $job->captchaDriver === null
                ? $attempt->captcha_driver
                : mb_substr($job->captchaDriver, 0, 32),
            'captcha_cost_micros' => $job->captchaCostMicros,
            'safe_metadata' => $safeMetadata,
            'result_payload_encrypted' => $result,
            'started_at' => $attempt->started_at ?? now(),
            'last_synced_at' => now(),
            'submitted_at' => $submitted ? ($attempt->submitted_at ?? now()) : $attempt->submitted_at,
            'sync_lost_at' => null,
            'finished_at' => $job->status->isTerminal() ? now() : null,
        ])->save();

        return $attempt->refresh();
    }

    public function markSyncLost(MeiAutomationAttempt $attempt): MeiAutomationAttempt
    {
        $submitted = $attempt->submitted_at !== null;
        $attempt->forceFill([
            'status' => $submitted ? MeiAutomationStatus::Uncertain : MeiAutomationStatus::SyncLost,
            'error_code' => $submitted ? 'SYNC_LOST_AFTER_SUBMISSION' : 'SYNC_LOST',
            'error_message' => $submitted
                ? 'Estado efêmero perdido após possível submissão; reconciliação obrigatória.'
                : 'Estado efêmero do job expirou antes da sincronização.',
            'last_synced_at' => now(),
            'sync_lost_at' => now(),
            'finished_at' => now(),
        ])->save();

        return $attempt->refresh();
    }

    /** @param array{id:string,name:string,content_type:string,byte_size:int,sha256:string,object_id:string} $artifact */
    public function recordVaultArtifact(MeiAutomationAttempt $attempt, array $artifact): MeiAutomationAttempt
    {
        return DB::transaction(function () use ($attempt, $artifact): MeiAutomationAttempt {
            $locked = MeiAutomationAttempt::query()
                ->withoutGlobalScopes()
                ->where('office_id', $attempt->office_id)
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();
            $artifacts = collect($locked->vault_artifacts ?? [])
                ->filter(static fn (mixed $item): bool => is_array($item));
            if (! $artifacts->contains(fn (array $item): bool => ($item['id'] ?? null) === $artifact['id'])) {
                $artifacts->push($artifact);
            }
            $metadata = (array) ($locked->safe_metadata ?? []);
            $metadata['artifact_count'] = $artifacts->count();
            $locked->forceFill([
                'vault_artifacts' => $artifacts->values()->all(),
                'safe_metadata' => $this->sanitizer->sanitize($metadata),
                'last_synced_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    public function markArtifactFailure(MeiAutomationAttempt $attempt, string $code, string $message): MeiAutomationAttempt
    {
        $attempt->forceFill([
            'status' => MeiAutomationStatus::Failed,
            'error_code' => mb_substr($code, 0, 80),
            'error_message' => $this->sanitizer->error($message),
            'last_synced_at' => now(),
            'finished_at' => now(),
        ])->save();

        return $attempt->refresh();
    }

    public function markFallback(MeiAutomationAttempt $attempt, string $reason): MeiAutomationAttempt
    {
        $attempt->forceFill([
            'fallback_reason' => mb_substr($reason, 0, 80),
            'last_synced_at' => now(),
        ])->save();

        return $attempt->refresh();
    }
}
