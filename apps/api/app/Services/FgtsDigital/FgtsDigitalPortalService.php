<?php

namespace App\Services\FgtsDigital;

use App\Contracts\FgtsDigitalPortalClient;
use App\Contracts\SecureObjectStore;
use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;
use App\Enums\FgtsDigitalGuideType;
use App\Enums\FgtsDigitalOperation;
use App\Enums\FgtsDigitalRunStatus;
use App\Enums\FiscalMutationStatus;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproEnvironment;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxGuideRiskLevel;
use App\Models\Client;
use App\Models\FgtsDigitalRun;
use App\Models\FiscalMutationOperation;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\Fiscal\Guides\GuideStorageService;
use App\Support\LogSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class FgtsDigitalPortalService
{
    public function __construct(
        private readonly FgtsDigitalPortalClient $portal,
        private readonly FgtsDigitalCredentialResolver $credentials,
        private readonly FgtsDigitalSessionStore $sessions,
        private readonly FgtsDigitalReadinessService $readiness,
        private readonly GuideStorageService $guideStorage,
        private readonly SecureObjectStore $vault,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function createQueryRun(Tenant $tenant, Client $client, ?User $user, array $parameters = []): FgtsDigitalRun
    {
        $this->assertTenant($tenant, $client);
        $private = $this->normalizeParameters($parameters);
        $safe = $this->sanitizeParameters($private);
        $run = null;

        try {
            return DB::transaction(function () use (
                $tenant,
                $client,
                $user,
                $private,
                $safe,
                &$run,
            ): FgtsDigitalRun {
                $run = FgtsDigitalRun::query()->create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'requested_by' => $user?->id,
                    'operation' => FgtsDigitalOperation::QueryGuides,
                    'status' => FgtsDigitalRunStatus::Pending,
                    'idempotency_key' => 'fgts-query|'.Str::uuid(),
                    'request_digest' => $this->digest($private),
                    'request_sanitized' => $safe,
                    'correlation_id' => (string) Str::uuid(),
                ]);
                $this->storePrivateRequest($run, $private);

                return $run->fresh();
            });
        } catch (\Throwable $exception) {
            if ($run instanceof FgtsDigitalRun) {
                $this->discardPrivateRequestObject($run);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{run:FgtsDigitalRun,preview_token:?string}
     */
    public function preview(
        Tenant $tenant,
        Client $client,
        User $user,
        FgtsDigitalGuideType $guideType,
        array $parameters,
    ): array {
        $this->assertTenant($tenant, $client);
        $private = $this->normalizeParameters([...$parameters, 'guide_type' => $guideType->value]);
        $safe = $this->sanitizeParameters($private);
        $digest = $this->digest($private);
        $token = Str::random(48);
        $phrase = $this->confirmationPhrase($safe, $guideType);
        $expires = now()->addSeconds((int) config('fgts_digital.preview_ttl_seconds', 300));
        $correlation = (string) Str::uuid();

        $run = null;
        try {
            DB::transaction(function () use (
                $tenant,
                $client,
                $user,
                $guideType,
                $safe,
                $correlation,
                $token,
                $phrase,
                $expires,
                $digest,
                $private,
                &$run,
            ): void {
                $mutation = FiscalMutationOperation::query()->create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'requested_by' => $user->id,
                    'idempotency_key' => 'fgts-preflight|'.$correlation,
                    'logical_key' => 'fgts-digital|'.$client->id.'|'
                        .$guideType->value.'|'
                        .($safe['competence_period_key'] ?? 'none'),
                    'correlation_id' => $correlation,
                    'preflight_token' => hash('sha256', $token),
                    'environment' => SerproEnvironment::Production,
                    'solution_code' => 'FGTS_DIGITAL',
                    'service_code' => 'GUIAS',
                    'operation_code' => 'EMITIR_GUIA',
                    'operation_key' => 'fgts_digital.emitir_guia',
                    'module_key' => 'fgts',
                    'competence_period_key' => $safe['competence_period_key']
                        ?? null,
                    'status' => FiscalMutationStatus::Pending,
                    'effect_summary' => 'Emissão de guia no portal FGTS Digital; pagamento não é realizado.',
                    'confirmation_phrase' => $phrase,
                    'confirmation_required' => true,
                    'confirmed_by_user' => false,
                    'request_sanitized' => $safe,
                    'preflight_at' => now(),
                    'preflight_expires_at' => $expires,
                    'simulated' => (string) config(
                        'fgts_digital.driver',
                    ) === 'fixture',
                ]);

                $run = FgtsDigitalRun::query()->create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'requested_by' => $user->id,
                    'fiscal_mutation_operation_id' => $mutation->id,
                    'operation' => FgtsDigitalOperation::Preview,
                    'guide_type' => $guideType,
                    'status' => FgtsDigitalRunStatus::Pending,
                    'idempotency_key' => 'fgts-preview|'.$correlation,
                    'request_digest' => $digest,
                    'preview_token_hash' => hash('sha256', $token),
                    'confirmation_phrase' => $phrase,
                    'preview_expires_at' => $expires,
                    'request_sanitized' => $safe,
                    'correlation_id' => $correlation,
                ]);
                $this->storePrivateRequest($run, $private);
            });
        } catch (\Throwable $exception) {
            if ($run instanceof FgtsDigitalRun) {
                $this->discardPrivateRequestObject($run);
            }

            throw $exception;
        }

        if (! $run instanceof FgtsDigitalRun) {
            throw new FgtsDigitalException(
                'Não foi possível criar a prévia FGTS Digital.',
                'FGTS_DIGITAL_PREVIEW_STORE_FAILED',
                500,
            );
        }
        $run = $this->executeRun($run);
        if ($run->status !== FgtsDigitalRunStatus::Previewed) {
            $token = null;
        }

        return ['run' => $run, 'preview_token' => $token];
    }

    /** @return array{run:FgtsDigitalRun,reused:bool} */
    public function authorizeEmission(
        Tenant $tenant,
        FgtsDigitalRun $preview,
        User $user,
        string $previewToken,
        string $confirmationPhrase,
    ): array {
        if (! (bool) config('fgts_digital.mutations_enabled', false)) {
            throw new FgtsDigitalException('Emissões FGTS Digital desabilitadas.', 'FGTS_DIGITAL_MUTATIONS_DISABLED', 403);
        }

        $previewObjectId = null;
        $newRun = null;
        try {
            $result = DB::transaction(function () use (
                $tenant,
                $preview,
                $user,
                $previewToken,
                $confirmationPhrase,
                &$previewObjectId,
                &$newRun,
            ): array {
                $lockedPreview = FgtsDigitalRun::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($preview->id)
                    ->lockForUpdate()
                    ->first();
                if ($lockedPreview === null
                    || $lockedPreview->operation
                        !== FgtsDigitalOperation::Preview) {
                    throw new FgtsDigitalException(
                        'Prévia não encontrada.',
                        'FGTS_DIGITAL_PREVIEW_NOT_FOUND',
                        404,
                    );
                }
                if ($lockedPreview->status
                        !== FgtsDigitalRunStatus::Previewed
                    || $lockedPreview->preview_expires_at === null
                    || $lockedPreview->preview_expires_at->isPast()) {
                    throw new FgtsDigitalException(
                        'Prévia expirada ou indisponível.',
                        'FGTS_DIGITAL_PREVIEW_EXPIRED',
                        409,
                    );
                }
                if (! hash_equals(
                    (string) $lockedPreview->preview_token_hash,
                    hash('sha256', $previewToken),
                )) {
                    throw new FgtsDigitalException(
                        'Token da prévia inválido.',
                        'FGTS_DIGITAL_PREVIEW_TOKEN_INVALID',
                        403,
                    );
                }
                if (! hash_equals(
                    mb_strtolower(trim(
                        (string) $lockedPreview->confirmation_phrase,
                    )),
                    mb_strtolower(trim($confirmationPhrase)),
                )) {
                    throw new FgtsDigitalException(
                        'Frase de confirmação divergente.',
                        'FGTS_DIGITAL_CONFIRMATION_MISMATCH',
                        422,
                    );
                }

                $idempotency = 'fgts-emit|'.$lockedPreview->client_id
                    .'|'.$lockedPreview->request_digest;
                $existing = FgtsDigitalRun::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotency)
                    ->first();
                $previewObjectId = $lockedPreview->request_vault_object_id;
                if ($existing !== null) {
                    return ['run' => $existing, 'reused' => true];
                }

                $private = $this->loadPrivateRequest($lockedPreview);
                if (! hash_equals(
                    (string) $lockedPreview->request_digest,
                    $this->digest($private),
                )) {
                    throw new FgtsDigitalException(
                        'Seleção da prévia divergiu.',
                        'FGTS_DIGITAL_PREVIEW_SELECTION_MISMATCH',
                        409,
                    );
                }

                $mutation = FiscalMutationOperation::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey(
                        $lockedPreview->fiscal_mutation_operation_id,
                    )
                    ->lockForUpdate()
                    ->first();
                if ($mutation === null) {
                    throw new FgtsDigitalException(
                        'Prévia fiscal não encontrada.',
                        'FGTS_DIGITAL_MUTATION_NOT_FOUND',
                        409,
                    );
                }
                $mutation->forceFill([
                    'confirmed_by_user' => true,
                    'confirmed_at' => now(),
                ])->save();

                $newRun = FgtsDigitalRun::query()->create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $lockedPreview->client_id,
                    'requested_by' => $user->id,
                    'fiscal_mutation_operation_id' => $mutation->id,
                    'operation' => FgtsDigitalOperation::EmitGuide,
                    'guide_type' => $lockedPreview->guide_type,
                    'status' => FgtsDigitalRunStatus::Authorized,
                    'idempotency_key' => $idempotency,
                    'request_digest' => $lockedPreview->request_digest,
                    'request_sanitized' => $lockedPreview->request_sanitized,
                    'correlation_id' => $lockedPreview->correlation_id,
                ]);
                $this->storePrivateRequest($newRun, $private);

                return ['run' => $newRun, 'reused' => false];
            });
        } catch (\Throwable $exception) {
            if ($newRun instanceof FgtsDigitalRun) {
                $this->discardPrivateRequestObject($newRun);
            }

            throw $exception;
        }

        $this->discardPreviewPrivateRequest(
            (int) $tenant->id,
            (int) $preview->id,
            $previewObjectId,
        );

        return $result;
    }

    public function executeRun(FgtsDigitalRun $run): FgtsDigitalRun
    {
        $timeout = (int) config('fgts_digital.runtime.timeout_seconds', 120);
        $lock = Cache::lock('fgts-digital:run:'.$run->tenant_id.':'.$run->client_id, $timeout + 30);
        if (! $lock->get()) {
            throw new FgtsDigitalException('Já existe execução FGTS Digital para o cliente.', 'FGTS_DIGITAL_CONCURRENT_RUN', 409);
        }

        try {
            $tenant = Tenant::query()->find($run->tenant_id);
            $client = Client::query()->withoutGlobalScopes()
                ->where('tenant_id', $run->tenant_id)
                ->whereKey($run->client_id)
                ->first();
            if ($tenant === null || $client === null) {
                throw new FgtsDigitalException('Tenant da execução não encontrado.', 'FGTS_DIGITAL_TENANT_NOT_FOUND', 404);
            }
            $ready = $this->readiness->check($tenant, $client);
            if (! $ready->readyForRead) {
                $run->forceFill([
                    'status' => FgtsDigitalRunStatus::Blocked,
                    'code' => $ready->firstBlockerCode(),
                    'result_sanitized' => [
                        'message' => $ready->firstBlockerMessage(),
                    ],
                    'finished_at' => now(),
                ])->save();

                return $run->fresh();
            }

            $credential = $this->credentials->resolve($tenant, $client, includeMaterial: true);
            if ($credential === null) {
                throw new FgtsDigitalException('Credencial FGTS Digital indisponível.', 'FGTS_DIGITAL_CREDENTIAL_MISSING', 422);
            }
            $targetIdentifierHash = FgtsDigitalCredentialResolver::identifierHash((string) $client->root_cnpj);
            $session = $this->sessions->latest(
                (int) $tenant->id,
                (int) $client->id,
                $credential['fingerprint'],
                $credential['profile_type'],
                $targetIdentifierHash,
            );
            $storageState = $this->sessions->load($session);

            $run->forceFill(['status' => FgtsDigitalRunStatus::Running, 'started_at' => now(), 'session_id' => $session?->id])->save();
            $mutation = $this->mutationFor($run);
            if ($run->operation === FgtsDigitalOperation::EmitGuide && $mutation !== null) {
                $mutation->forceFill([
                    'status' => FiscalMutationStatus::Sent,
                    'sent_at' => now(),
                    'attempt_count' => ((int) $mutation->attempt_count) + 1,
                ])->save();
            }

            $privateParameters = $this->loadPrivateRequest($run);
            $privateParameters['_selection_fingerprint'] = hash_hmac(
                'sha256',
                (string) $run->request_digest,
                (string) config('app.key'),
            );
            try {
                $result = $this->portal->execute(new FgtsDigitalPortalRequest(
                    operation: $run->operation,
                    tenantId: (int) $tenant->id,
                    clientId: (int) $client->id,
                    targetIdentifier: (string) $client->root_cnpj,
                    credentialSource: $credential['source']->value,
                    profileType: $credential['profile_type'],
                    parameters: $privateParameters,
                    pfx: $credential['pfx'],
                    pfxPassword: $credential['password'],
                    storageState: $storageState,
                ));
            } finally {
                $credential['pfx'] = null;
                $credential['password'] = null;
                $storageState = null;
                $privateParameters = [];
            }

            if ($result->storageState !== null) {
                $session = $this->sessions->store(
                    (int) $tenant->id,
                    (int) $client->id,
                    $credential['source'],
                    $credential['fingerprint'],
                    $credential['profile_type'],
                    $targetIdentifierHash,
                    $result->storageState,
                    $credential['representation_id'],
                );
            }

            $status = $run->operation === FgtsDigitalOperation::Preview && $result->status === 'SUCCEEDED'
                ? FgtsDigitalRunStatus::Previewed
                : $this->statusFromResult($result);
            $persisted = in_array($result->status, ['SUCCEEDED', 'REUSED'], true)
                ? $this->persistGuides($tenant, $client, $run, $result)
                : [];
            $last = end($persisted) ?: null;

            $run->forceFill([
                'status' => $status,
                'code' => $result->code,
                'session_id' => $session?->id,
                'tax_guide_id' => $last['guide']->id ?? null,
                'tax_guide_version_id' => $last['version']?->id ?? null,
                'result_sanitized' => $result->toPublicArray(),
                'finished_at' => now(),
            ])->save();
            $this->finishMutation(
                $run->operation === FgtsDigitalOperation::EmitGuide ? $mutation : null,
                $status,
                $result,
                $last,
            );

            return $run->fresh();
        } finally {
            $fresh = $run->fresh();
            if ($fresh !== null && ! ($fresh->operation === FgtsDigitalOperation::Preview
                && $fresh->status === FgtsDigitalRunStatus::Previewed)) {
                $this->deletePrivateRequest($fresh);
            }
            $lock->release();
        }
    }

    /** @return list<array{guide:TaxGuide,version:?TaxGuideVersion,reused:bool}> */
    private function persistGuides(Tenant $tenant, Client $client, FgtsDigitalRun $run, FgtsDigitalPortalResult $result): array
    {
        $guides = $result->data['guides'] ?? [];
        if (! is_array($guides)) {
            throw new FgtsDigitalException('Lista de guias inválida.', 'FGTS_DIGITAL_GUIDES_INVALID', 502);
        }
        $stored = [];
        foreach ($guides as $row) {
            if (! is_array($row)) {
                continue;
            }
            $identifier = trim((string) ($row['identifier'] ?? ''));
            if ($identifier === '') {
                throw new FgtsDigitalException('Guia sem identificador oficial.', 'FGTS_DIGITAL_GUIDE_IDENTIFIER_MISSING', 502);
            }
            $competence = $this->normalizeCompetence((string) ($row['competence'] ?? ($run->request_sanitized['competence_period_key'] ?? '')));
            $guideType = strtoupper((string) ($row['guide_type'] ?? $run->guide_type?->value ?? 'MONTHLY'));
            $amount = isset($row['amount_cents']) ? (int) $row['amount_cents'] : null;
            $dueAt = $this->parsePortalDate($row['due_date'] ?? null);
            $payment = TaxGuidePaymentStatus::tryFrom(strtoupper((string) ($row['payment_status'] ?? 'UNKNOWN')))
                ?? TaxGuidePaymentStatus::Unknown;
            $checkedAt = $this->parsePortalDate($row['checked_at'] ?? null) ?? CarbonImmutable::now();
            $logical = 'gfd|'.hash('sha256', implode('|', [(string) $client->id, $identifier, $guideType]));

            $guide = TaxGuide::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('logical_key', $logical)
                ->first();
            $guide ??= TaxGuide::query()->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'operation_key' => (string) $run->operation_key,
                'system_code' => 'FGTS_DIGITAL',
                'service_code' => 'GUIAS',
                'operation_code' => $run->operation === FgtsDigitalOperation::EmitGuide ? 'EMITIR_GUIA' : 'CONSULTAR_GUIAS',
                'competence_period_key' => $competence,
                'debit_ref' => $identifier,
                'logical_key' => $logical,
                'payment_status' => $payment,
                'amount_cents' => $amount,
                'due_at' => $dueAt,
                'identifier_code' => $identifier,
                'created_by' => $run->requested_by,
                'metadata' => [
                    'source' => 'FGTS_DIGITAL_PORTAL',
                    'guide_type' => $guideType,
                    'checked_at' => $checkedAt->toIso8601String(),
                ],
            ]);
            $guide->forceFill([
                'payment_status' => $payment,
                'payment_confirmed_at' => $payment === TaxGuidePaymentStatus::Confirmed ? now() : null,
                'payment_source' => 'FGTS_DIGITAL_PORTAL',
                'amount_cents' => $amount,
                'due_at' => $dueAt,
                'identifier_code' => $identifier,
                'competence_period_key' => $competence,
                'metadata' => [
                    'source' => 'FGTS_DIGITAL_PORTAL',
                    'guide_type' => $guideType,
                    'checked_at' => $checkedAt->toIso8601String(),
                ],
            ])->save();

            $artifactIndex = isset($row['artifact_index']) ? (int) $row['artifact_index'] : null;
            $artifact = $artifactIndex !== null ? ($result->artifacts[$artifactIndex] ?? null) : null;
            if ($artifact === null) {
                $stored[] = ['guide' => $guide, 'version' => $guide->currentVersion, 'reused' => true];

                continue;
            }
            if (! str_starts_with($artifact['bytes'], '%PDF-')) {
                throw new FgtsDigitalException('Portal devolveu documento que não é PDF.', 'FGTS_DIGITAL_INVALID_PDF', 502);
            }
            $current = TaxGuideVersion::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('tax_guide_id', $guide->id)
                ->where('is_current', true)
                ->first();
            if ($current !== null && hash_equals((string) $current->content_sha256, $artifact['sha256'])) {
                $stored[] = ['guide' => $guide, 'version' => $current, 'reused' => true];

                continue;
            }

            $object = $this->guideStorage->storeDocument((int) $tenant->id, $artifact['bytes'], 'application/pdf');
            $version = DB::transaction(function () use ($tenant, $guide, $current, $object, $identifier, $amount, $dueAt, $run, $artifact): TaxGuideVersion {
                if ($current !== null) {
                    $current->forceFill([
                        'is_current' => false,
                        'emission_status' => TaxGuideEmissionStatus::Superseded,
                    ])->save();
                }
                $version = TaxGuideVersion::query()->create([
                    'tenant_id' => $tenant->id,
                    'tax_guide_id' => $guide->id,
                    'version_number' => ((int) ($current?->version_number ?? 0)) + 1,
                    'is_current' => true,
                    'emission_status' => TaxGuideEmissionStatus::Confirmed,
                    'replaces_version_id' => $current?->id,
                    'identifier_code' => $identifier,
                    'amount_cents' => $amount,
                    'due_at' => $dueAt,
                    ...$object,
                    'idempotency_key' => 'gfd|'.hash('sha256', $guide->id.'|'.$artifact['sha256']),
                    'correlation_id' => $run->correlation_id,
                    'remote_protocol' => $identifier,
                    'risk_level' => $run->operation === FgtsDigitalOperation::EmitGuide ? TaxGuideRiskLevel::High : TaxGuideRiskLevel::Standard,
                    'confirmed_by_user_id' => $run->operation === FgtsDigitalOperation::EmitGuide ? $run->requested_by : null,
                    'confirmed_at' => $run->operation === FgtsDigitalOperation::EmitGuide ? now() : null,
                    'issued_by' => $run->requested_by,
                    'sent_at' => $run->started_at,
                    'finished_at' => now(),
                    'metadata' => ['source' => 'FGTS_DIGITAL_PORTAL', 'artifact_name' => $artifact['name']],
                ]);
                $guide->forceFill(['current_version_id' => $version->id])->save();

                return $version;
            });
            $stored[] = ['guide' => $guide->fresh(), 'version' => $version, 'reused' => false];
        }

        return $stored;
    }

    private function statusFromResult(FgtsDigitalPortalResult $result): FgtsDigitalRunStatus
    {
        return match ($result->status) {
            'SUCCEEDED' => FgtsDigitalRunStatus::Succeeded,
            'REUSED' => FgtsDigitalRunStatus::Reused,
            'HUMAN_CHALLENGE_REQUIRED' => FgtsDigitalRunStatus::HumanChallengeRequired,
            'PORTAL_CONTRACT_CHANGED' => FgtsDigitalRunStatus::ContractChanged,
            'RECONCILIATION_REQUIRED' => FgtsDigitalRunStatus::ReconciliationRequired,
            'BLOCKED' => FgtsDigitalRunStatus::Blocked,
            default => FgtsDigitalRunStatus::Failed,
        };
    }

    /** @param array{guide:TaxGuide,version:?TaxGuideVersion,reused:bool}|null $last */
    private function finishMutation(?FiscalMutationOperation $mutation, FgtsDigitalRunStatus $status, FgtsDigitalPortalResult $result, ?array $last): void
    {
        if ($mutation === null) {
            return;
        }
        $mutationStatus = match ($status) {
            FgtsDigitalRunStatus::Succeeded, FgtsDigitalRunStatus::Reused => FiscalMutationStatus::Confirmed,
            FgtsDigitalRunStatus::ReconciliationRequired => FiscalMutationStatus::UnknownResult,
            FgtsDigitalRunStatus::Running => FiscalMutationStatus::Sent,
            default => FiscalMutationStatus::Rejected,
        };
        $mutation->forceFill([
            'status' => $mutationStatus,
            'result_code' => $result->code,
            'result_message' => $result->message,
            'result_sanitized' => $result->toPublicArray(),
            'evidence_ref' => isset($last['version']) && $last['version'] !== null ? 'tax_guide_version:'.$last['version']->id : null,
            'terminal_at' => $mutationStatus->isTerminal() ? now() : null,
        ])->save();
    }

    private function mutationFor(FgtsDigitalRun $run): ?FiscalMutationOperation
    {
        if ($run->fiscal_mutation_operation_id === null) {
            return null;
        }

        return FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)
            ->whereKey($run->fiscal_mutation_operation_id)
            ->first();
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    private function normalizeParameters(array $parameters): array
    {
        $allowed = [
            'competence_period_key', 'guide_type', 'amount_cents', 'due_at', 'establishment_id',
            'employee_ids', 'debit_ids', 'include_monthly', 'include_termination', 'include_consignment',
        ];
        $private = array_intersect_key($parameters, array_flip($allowed));
        if (isset($private['competence_period_key'])
            && ! preg_match('/^\d{4}-\d{2}$/', (string) $private['competence_period_key'])) {
            throw new FgtsDigitalException('Competência inválida.', 'FGTS_DIGITAL_COMPETENCE_INVALID', 422);
        }
        if (isset($private['guide_type'])
            && ! in_array((string) $private['guide_type'], (array) config('fgts_digital.allowed_guide_types'), true)) {
            throw new FgtsDigitalException('Tipo de guia inválido.', 'FGTS_DIGITAL_GUIDE_TYPE_INVALID', 422);
        }
        foreach (['employee_ids', 'debit_ids'] as $selectionKey) {
            if (isset($private[$selectionKey]) && ! is_array($private[$selectionKey])) {
                throw new FgtsDigitalException('Seleção parametrizada inválida.', 'FGTS_DIGITAL_SELECTION_INVALID', 422);
            }
            if (isset($private[$selectionKey])) {
                $private[$selectionKey] = array_values(array_unique(array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $private[$selectionKey],
                )));
            }
        }
        ksort($private);

        return $private;
    }

    /** @param array<string, mixed> $private @return array<string, mixed> */
    private function sanitizeParameters(array $private): array
    {
        $safe = $private;
        foreach (['employee_ids', 'debit_ids'] as $selectionKey) {
            if (! isset($safe[$selectionKey]) || ! is_array($safe[$selectionKey])) {
                continue;
            }
            $values = $safe[$selectionKey];
            unset($safe[$selectionKey]);
            $prefix = $selectionKey === 'employee_ids' ? 'employee' : 'debit';
            $safe[$prefix.'_count'] = count($values);
            $safe[$prefix.'_selection_hash'] = hash_hmac(
                'sha256',
                json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                (string) config('app.key'),
            );
        }
        ksort($safe);

        return $safe;
    }

    /** @param array<string, mixed> $safe */
    private function digest(array $safe): string
    {
        return hash('sha256', json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $private */
    private function storePrivateRequest(FgtsDigitalRun $run, array $private): void
    {
        try {
            $objectId = $this->vault->put(
                json_encode($private, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $this->requestAad($run),
            );
            $run->forceFill(['request_vault_object_id' => $objectId])->save();
        } catch (\Throwable) {
            $run->delete();
            throw new FgtsDigitalException('Não foi possível proteger a seleção FGTS Digital.', 'FGTS_DIGITAL_REQUEST_STORE_FAILED', 500);
        }
    }

    /** @return array<string, mixed> */
    private function loadPrivateRequest(FgtsDigitalRun $run): array
    {
        if ($run->request_vault_object_id === null) {
            throw new FgtsDigitalException('Seleção privada da execução não encontrada.', 'FGTS_DIGITAL_REQUEST_MISSING', 409);
        }
        try {
            $json = $this->vault->get((string) $run->request_vault_object_id, $this->requestAad($run));
            $private = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new FgtsDigitalException('Seleção privada da execução indisponível.', 'FGTS_DIGITAL_REQUEST_INVALID', 409);
        }
        if (! is_array($private) || ! hash_equals((string) $run->request_digest, $this->digest($private))) {
            throw new FgtsDigitalException('Seleção privada da execução divergiu.', 'FGTS_DIGITAL_REQUEST_DIGEST_MISMATCH', 409);
        }

        return $private;
    }

    private function deletePrivateRequest(FgtsDigitalRun $run): void
    {
        $objectId = $run->request_vault_object_id;
        if ($objectId === null) {
            return;
        }
        if (! $this->discardVaultObject((string) $objectId)) {
            return;
        }
        $run->forceFill(['request_vault_object_id' => null])->save();
    }

    private function discardPrivateRequestObject(FgtsDigitalRun $run): void
    {
        $objectId = $run->request_vault_object_id;
        if ($objectId === null) {
            return;
        }

        $this->discardVaultObject((string) $objectId);
        $run->forceFill(['request_vault_object_id' => null]);
    }

    private function discardPreviewPrivateRequest(
        int $tenantId,
        int $previewId,
        ?string $objectId,
    ): void {
        if ($objectId === null || ! $this->discardVaultObject($objectId)) {
            return;
        }

        try {
            FgtsDigitalRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($previewId)
                ->where('request_vault_object_id', $objectId)
                ->update(['request_vault_object_id' => null]);
        } catch (\Throwable) {
            Log::warning(
                'fgts digital preview cleanup state update failed',
                LogSanitizer::redact([
                    'tenant_id' => $tenantId,
                    'run_id' => $previewId,
                ]),
            );
        }
    }

    private function discardVaultObject(?string $objectId): bool
    {
        if ($objectId === null || $objectId === '') {
            return true;
        }

        try {
            $this->vault->delete($objectId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, scalar|null> */
    private function requestAad(FgtsDigitalRun $run): array
    {
        return SecureObjectPurpose::FgtsDigitalRequest->aadBase([
            'tenant_id' => (int) $run->tenant_id,
            'client_id' => (int) $run->client_id,
            'run_id' => (int) $run->id,
            'request_digest' => (string) $run->request_digest,
        ]);
    }

    /** @param array<string, mixed> $safe */
    private function confirmationPhrase(array $safe, FgtsDigitalGuideType $type): string
    {
        return 'EMITIR FGTS '.($safe['competence_period_key'] ?? 'SEM-COMPETENCIA').' '.$type->value;
    }

    private function normalizeCompetence(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $match)) {
            return $match[1].'-'.$match[2];
        }
        if (preg_match('/^(\d{2})\/(\d{4})$/', $value, $match)) {
            return $match[2].'-'.$match[1];
        }

        return null;
    }

    private function parsePortalDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
            return CarbonImmutable::createFromFormat('d/m/Y', $value)->startOfDay();
        }

        return CarbonImmutable::parse($value);
    }

    private function assertTenant(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new FgtsDigitalException('Cliente não encontrado.', 'FGTS_DIGITAL_CLIENT_NOT_FOUND', 404);
        }
    }
}
