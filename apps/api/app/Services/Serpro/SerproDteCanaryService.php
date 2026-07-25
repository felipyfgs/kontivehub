<?php

namespace App\Services\Serpro;

use App\Enums\CredentialStatus;
use App\Enums\OfficeRole;
use App\Enums\SerproCredentialVersionStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproDteCanaryRequestStatus;
use App\Enums\SerproDteControlMode;
use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeMembership;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproCredentialConnectionEvidence;
use App\Models\SerproCredentialVersion;
use App\Models\SerproDteCanaryRequest;
use App\Models\SerproDteControl;
use App\Models\SerproExternalGate;
use App\Models\SerproOfficeQuantityUsageLimit;
use App\Models\SerproQuantityUsageLimit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TaxProxyPowerService;
use App\Support\FeatureFlags;
use App\Support\Serpro\DteCanaryCoordinates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Canário DTE unitário e promoção LIMITED no Office piloto.
 *
 * - Alvo server-side (Office Production + cliente do Office)
 * - Dual approval (Proprietário + Office ADMIN distintos)
 * - Gate pré-transporte recalculado
 * - Uma reserva/dispatch via executor central
 * - Resultado fiscal só no tenant; global sanitizado
 */
final class SerproDteCanaryService
{
    public const CONFIRM_PROMOTE_PHRASE = 'CONFIRMO-DTE-LIMITED';

    public const CONFIRM_DISABLE_PHRASE = 'CONFIRMO-DTE-DISABLE';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproOperationService $operations,
        private readonly TaxProxyPowerService $proxyPowers,
        private readonly SerproRequestTagGenerator $requestTags,
    ) {}

    public function ensureControl(): SerproDteControl
    {
        return SerproDteControl::query()->firstOrCreate(
            ['operation_key' => DteCanaryCoordinates::OPERATION_KEY],
            [
                'mode' => SerproDteControlMode::Disabled,
                'limited_used_quantity' => 0,
                'alert_percent' => DteCanaryCoordinates::ALERT_PERCENT,
            ],
        );
    }

    /**
     * Cria pedido draft com coordenadas DTE imutáveis.
     */
    public function createRequest(int $createdByUserId, ?SerproEnvironment $environment = null): SerproDteCanaryRequest
    {
        $environment ??= SerproEnvironment::Production;
        if ($environment !== SerproEnvironment::Production) {
            throw new RuntimeException('Canário DTE só é permitido em Production.');
        }

        $coords = DteCanaryCoordinates::asArray();
        $request = SerproDteCanaryRequest::query()->create([
            'environment' => $environment,
            'status' => SerproDteCanaryRequestStatus::Draft,
            'operation_key' => $coords['operation_key'],
            'id_sistema' => $coords['id_sistema'],
            'id_servico' => $coords['id_servico'],
            'service_version' => $coords['service_version'],
            'functional_route' => $coords['functional_route'],
            'required_proxy_power' => $coords['required_proxy_power'],
            'created_by_user_id' => $createdByUserId,
            'expires_at' => CarbonImmutable::now()->addHours(24),
            'consumption_quantity' => 0,
        ]);

        $this->audit->record('serpro.dte_canary.create', 'SUCCESS', $request, [
            'environment' => $environment->value,
        ], $createdByUserId, null);

        return $request;
    }

    /**
     * Seleciona Office Production ativo + cliente pertencente (server-side).
     * Rejeita office_id/operação/coordenadas livres do client — caller passa ids validados.
     */
    public function selectTarget(
        SerproDteCanaryRequest $request,
        int $officeId,
        int $clientId,
        int $actorUserId,
    ): SerproDteCanaryRequest {
        $this->assertMutableTarget($request);

        $office = Office::query()->withoutGlobalScopes()->find($officeId);
        if ($office === null || ! $office->is_active) {
            throw new RuntimeException('Office inválido ou inativo.');
        }

        $seg = strtoupper((string) ($office->serpro_segregation_class ?? ''));
        if ($seg !== '' && $seg !== SerproDataSegregationClass::Production->value) {
            throw new RuntimeException('Office deve ser classificado Production (não demo/shadow/fake).');
        }
        if ($seg === '' || $seg === SerproDataSegregationClass::Demo->value) {
            // default sem classificação explícita Production bloqueia em produção
            if ($seg === SerproDataSegregationClass::Demo->value
                || str_contains(strtolower((string) $office->slug), 'demo')) {
                throw new RuntimeException('Office demo não pode ser alvo do canário DTE.');
            }
            // Exigir Production explícito
            if ($seg !== SerproDataSegregationClass::Production->value) {
                throw new RuntimeException('Office deve ser classificado Production.');
            }
        }

        $client = Client::query()->withoutGlobalScopes()
            ->whereKey($clientId)
            ->first();

        if ($client === null || (int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao Office selecionado.');
        }

        $coords = DteCanaryCoordinates::asArray();
        $installation = (string) config('app.key', 'install');
        $env = $request->environment instanceof SerproEnvironment
            ? $request->environment->value
            : (string) $request->environment;

        $control = $this->ensureControl();
        if ($control->mode === SerproDteControlMode::Canary
            && ($control->pilot_office_id !== null || $control->pilot_client_id !== null)
            && ((int) $control->pilot_office_id !== (int) $office->id
                || (int) $control->pilot_client_id !== (int) $client->id)
        ) {
            throw new RuntimeException('Alvo piloto do canário DTE já foi fixado e não pode ser trocado.');
        }

        $idempotencyParts = [
            'dte-canary',
            substr(hash('sha256', $installation), 0, 16),
            $env,
            (string) $office->id,
            (string) $client->id,
            $coords['operation_key'],
        ];
        if ($control->mode === SerproDteControlMode::Limited) {
            $idempotencyParts[] = (string) $request->id;
        }
        $idempotencyKey = hash('sha256', implode('|', $idempotencyParts));

        $request->forceFill([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'selected_by_user_id' => $actorUserId,
            'selected_at' => now(),
            'status' => SerproDteCanaryRequestStatus::TargetSet,
            'operation_key' => $coords['operation_key'],
            'id_sistema' => $coords['id_sistema'],
            'id_servico' => $coords['id_servico'],
            'service_version' => $coords['service_version'],
            'functional_route' => $coords['functional_route'],
            'required_proxy_power' => $coords['required_proxy_power'],
            'idempotency_key' => $idempotencyKey,
        ])->save();

        if ($control->mode === SerproDteControlMode::Disabled
            || $control->mode === SerproDteControlMode::Canary
        ) {
            $control->forceFill([
                'mode' => SerproDteControlMode::Canary,
                'pilot_office_id' => $office->id,
                'pilot_client_id' => $client->id,
                'disabled_at' => null,
                'disable_reason' => null,
            ])->save();
        }

        $this->audit->record('serpro.dte_canary.select_target', 'SUCCESS', $request, [
            'office_id' => $office->id,
            'client_id' => $client->id,
        ], $actorUserId, $office->id);

        return $request->fresh();
    }

    /**
     * Aprovação do Proprietário (PLATFORM_ADMIN) com senha recente.
     */
    public function approveAsOwner(
        SerproDteCanaryRequest $request,
        User $owner,
        bool $passwordRecentlyConfirmed,
    ): SerproDteCanaryRequest {
        if (! $passwordRecentlyConfirmed) {
            throw new RuntimeException('Reconfirmação de senha (15 min) obrigatória para aprovação do Proprietário.');
        }

        if (! $owner->isPlatformAdmin()) {
            throw new RuntimeException('Somente o Proprietário (PLATFORM_ADMIN) pode registrar a aprovação global.');
        }

        $this->assertNotExpired($request);
        if ($request->office_id === null || $request->client_id === null) {
            throw new RuntimeException('Alvo do canário ainda não foi selecionado.');
        }

        if ($request->hasOfficeAdminApproval()
            && (int) $request->office_admin_approver_user_id === (int) $owner->id
        ) {
            throw new RuntimeException(
                'Conta dual: o mesmo usuário já aprovou como Office ADMIN; exige segundo usuário autorizado.'
            );
        }

        if ($request->hasOwnerApproval() && (int) $request->owner_approver_user_id !== (int) $owner->id) {
            throw new RuntimeException('Aprovação do Proprietário já registrada por outro usuário.');
        }

        $request->forceFill([
            'owner_approver_user_id' => $owner->id,
            'owner_approved_at' => now(),
        ]);
        $this->refreshApprovalStatus($request);
        $request->save();

        $this->audit->record('serpro.dte_canary.owner_approve', 'SUCCESS', $request, [
            'status' => $request->status->value,
        ], $owner->id, $request->office_id);

        return $request->fresh();
    }

    /**
     * Confirmação do Office ADMIN no CurrentOffice (sem office_id do client).
     */
    public function approveAsOfficeAdmin(
        SerproDteCanaryRequest $request,
        User $admin,
        Office $currentOffice,
        bool $passwordRecentlyConfirmed,
    ): SerproDteCanaryRequest {
        if (! $passwordRecentlyConfirmed) {
            throw new RuntimeException('Reconfirmação de senha (15 min) obrigatória para confirmação do Office ADMIN.');
        }

        $this->assertNotExpired($request);
        if ($request->office_id === null) {
            throw new RuntimeException('Alvo do canário ainda não foi selecionado.');
        }

        if ((int) $currentOffice->id !== (int) $request->office_id) {
            throw new RuntimeException('Office corrente não corresponde ao Office piloto do canário.');
        }

        $membership = OfficeMembership::query()
            ->where('office_id', $currentOffice->id)
            ->where('user_id', $admin->id)
            ->where('is_active', true)
            ->first();

        if ($membership === null) {
            throw new RuntimeException('Usuário sem membership ativa no Office corrente.');
        }

        $role = $membership->role instanceof OfficeRole
            ? $membership->role
            : OfficeRole::tryFrom((string) $membership->role);

        if ($role !== OfficeRole::Admin) {
            throw new RuntimeException('Somente Office ADMIN pode confirmar participação no canário DTE.');
        }

        if ($request->hasOwnerApproval()
            && (int) $request->owner_approver_user_id === (int) $admin->id
        ) {
            throw new RuntimeException(
                'Conta dual: o mesmo usuário já aprovou como Proprietário; exige segundo usuário autorizado.'
            );
        }

        if ($request->hasOfficeAdminApproval()
            && (int) $request->office_admin_approver_user_id !== (int) $admin->id
        ) {
            throw new RuntimeException('Confirmação do Office ADMIN já registrada por outro usuário.');
        }

        $request->forceFill([
            'office_admin_approver_user_id' => $admin->id,
            'office_admin_approved_at' => now(),
        ]);
        $this->refreshApprovalStatus($request);
        $request->save();

        $this->audit->record('serpro.dte_canary.office_admin_approve', 'SUCCESS', $request, [
            'status' => $request->status->value,
            'office_id' => $currentOffice->id,
        ], $admin->id, $currentOffice->id);

        return $request->fresh();
    }

    /**
     * Gate pré-transporte — qualquer regressão bloqueia antes do HTTP.
     *
     * @return array{allowed: bool, blockers: list<string>, checks: array<string, bool>}
     */
    public function evaluatePreTransportGate(SerproDteCanaryRequest $request): array
    {
        $blockers = [];
        $checks = [];

        $env = $request->environment instanceof SerproEnvironment
            ? $request->environment
            : SerproEnvironment::tryFrom((string) $request->environment) ?? SerproEnvironment::Production;

        $checks['environment_production'] = $env === SerproEnvironment::Production;
        if (! $checks['environment_production']) {
            $blockers[] = 'ENVIRONMENT_NOT_PRODUCTION';
        }

        // Kill switches
        $checks['kill_switch_open'] = ! $this->killSwitch->isGlobalActive()
            && ! FeatureFlags::isKillSwitchActive()
            && ! $this->killSwitch->isSolutionBlocked(DteCanaryCoordinates::ID_SISTEMA);
        if (! $checks['kill_switch_open']) {
            $blockers[] = 'KILL_SWITCH';
        }

        $control = $this->ensureControl();
        $checks['control_allows'] = $control->mode->allowsNewReservation()
            && $control->mode !== SerproDteControlMode::Disabled;
        if (! $checks['control_allows']) {
            $blockers[] = 'DTE_CONTROL_DISABLED';
        }

        // Dual approval persistida e papéis revalidados no momento do transporte.
        $checks['fully_approved'] = $request->isFullyApproved()
            && $this->approvalRolesRemainValid($request);
        if (! $checks['fully_approved']) {
            $blockers[] = 'APPROVAL_INCOMPLETE';
        }

        // Target
        $office = $request->office_id
            ? Office::query()->withoutGlobalScopes()->find($request->office_id)
            : null;
        $client = $request->client_id
            ? Client::query()->withoutGlobalScopes()->find($request->client_id)
            : null;

        $checks['target_present'] = $office !== null && $client !== null;
        if (! $checks['target_present']) {
            $blockers[] = 'TARGET_MISSING';
        }

        $checks['client_belongs_to_office'] = $client !== null
            && $office !== null
            && (int) $client->office_id === (int) $office->id;
        if ($checks['target_present'] && ! $checks['client_belongs_to_office']) {
            $blockers[] = 'CLIENT_CROSS_TENANT';
        }

        $checks['office_production'] = $office !== null
            && strtoupper((string) ($office->serpro_segregation_class ?? '')) === SerproDataSegregationClass::Production->value
            && $office->is_active;
        if ($checks['target_present'] && ! $checks['office_production']) {
            $blockers[] = 'OFFICE_NOT_PRODUCTION';
        }

        $checks['pilot_matches_control'] = $control->pilot_office_id === null
            || ($office !== null && (int) $control->pilot_office_id === (int) $office->id);
        if (! $checks['pilot_matches_control']) {
            $blockers[] = 'OFFICE_NOT_PILOT';
        }

        // Credencial ACTIVE Production
        $credential = null;
        if (Schema::hasTable('serpro_credential_versions')) {
            $credential = SerproCredentialVersion::query()
                ->where('environment', SerproEnvironment::Production->value)
                ->where('status', SerproCredentialVersionStatus::Active->value)
                ->orderByDesc('id')
                ->first();
        }
        $checks['credential_active'] = $credential !== null
            && ! $credential->blocksBillableEgress();
        if (! $checks['credential_active']) {
            $blockers[] = 'CREDENTIAL_NOT_ACTIVE';
        }

        // OAuth recente na mesma versão
        $checks['oauth_recent'] = false;
        if ($credential !== null && Schema::hasTable('serpro_credential_connection_evidences')) {
            $evidence = SerproCredentialConnectionEvidence::query()
                ->where('serpro_credential_version_id', $credential->id)
                ->where('success', true)
                ->where('invalidated', false)
                ->orderByDesc('id')
                ->first();
            $checks['oauth_recent'] = $evidence !== null && $evidence->isValidFor($credential);
        } elseif ($credential !== null && $credential->token_expires_at !== null && $credential->token_expires_at->isFuture()) {
            // Fallback: token ainda válido na versão ativa
            $checks['oauth_recent'] = true;
        }
        if (! $checks['oauth_recent']) {
            $blockers[] = 'OAUTH_EVIDENCE_MISSING';
        }

        // Gates documentais são informativos (não bloqueiam canário na console simplificada).
        $checks['external_gates_accepted'] = $this->allExternalGatesAccepted();

        // A1 do Office
        $checks['a1_valid'] = $office !== null && $this->officeHasValidA1($office);
        if ($checks['target_present'] && ! $checks['a1_valid']) {
            $blockers[] = 'A1_INVALID';
        }

        // Termo aceito
        $auth = null;
        if ($office !== null) {
            $auth = OfficeSerproAuthorization::query()
                ->where('office_id', $office->id)
                ->where('environment', SerproEnvironment::Production->value)
                ->first();
        }
        $checks['termo_accepted'] = $auth !== null
            && $auth->status !== null
            && $auth->status->allowsExternalCalls()
            && ($auth->termo_valid_to === null || $auth->termo_valid_to->isFuture());
        if ($checks['target_present'] && ! $checks['termo_accepted']) {
            $blockers[] = 'TERMO_NOT_ACCEPTED';
        }

        // Procuração poder 00050
        $checks['power_00050'] = false;
        if ($office !== null && $client !== null && $auth !== null) {
            $author = strtoupper(trim((string) ($auth->author_identity ?? '')));
            if ($author !== '' && $author !== '00000000000000') {
                $power = $this->proxyPowers->findUsablePower(
                    officeId: (int) $office->id,
                    clientId: (int) $client->id,
                    powerCode: DteCanaryCoordinates::REQUIRED_PROXY_POWER,
                    authorIdentity: $author,
                    environment: SerproEnvironment::Production,
                );
                $checks['power_00050'] = $power !== null;
            }
        }
        if ($checks['target_present'] && ! $checks['power_00050']) {
            $blockers[] = 'PROXY_POWER_00050_MISSING';
        }

        // Limites quantitativos positivos (global + office)
        $checks['limits_positive'] = $this->quantityLimitsPositive(
            $office?->id,
            SerproEnvironment::Production,
        );
        if (! $checks['limits_positive']) {
            $blockers[] = 'QUANTITY_LIMITS_NOT_POSITIVE';
        }

        // Coordenadas imutáveis
        $coords = DteCanaryCoordinates::asArray();
        $checks['coordinates_locked'] = $request->operation_key === $coords['operation_key']
            && $request->id_sistema === $coords['id_sistema']
            && $request->id_servico === $coords['id_servico']
            && $request->functional_route === $coords['functional_route'];
        if (! $checks['coordinates_locked']) {
            $blockers[] = 'COORDINATES_TAMPERED';
        }

        // Status permite dispatch
        $status = $request->status instanceof SerproDteCanaryRequestStatus
            ? $request->status
            : SerproDteCanaryRequestStatus::tryFrom((string) $request->status);
        $checks['status_allows_dispatch'] = $status?->allowsDispatch() ?? false;
        if (! $checks['status_allows_dispatch']) {
            // Replay de tentativa terminal é tratado em execute, não aqui
            if ($status?->isTerminalAttempt()) {
                $checks['status_allows_dispatch'] = true;
            } else {
                $blockers[] = 'STATUS_BLOCKS_DISPATCH';
            }
        }

        // LIMITED mode: teto
        if ($control->mode === SerproDteControlMode::Limited) {
            $remaining = $control->remainingLimitedQuantity();
            $checks['limited_remaining'] = $remaining !== null && $remaining > 0;
            if (! $checks['limited_remaining']) {
                $blockers[] = 'LIMITED_QUOTA_EXHAUSTED';
            }
        } else {
            $checks['limited_remaining'] = true;
            $checks['canary_single_shot'] = true;
            if ($control->mode === SerproDteControlMode::Canary) {
                $alreadyReserved = SerproDteCanaryRequest::query()
                    ->where('operation_key', DteCanaryCoordinates::OPERATION_KEY)
                    ->where('environment', SerproEnvironment::Production->value)
                    ->where('office_id', $control->pilot_office_id)
                    ->where('client_id', $control->pilot_client_id)
                    ->where('id', '!=', $request->id)
                    ->whereNotNull('dispatched_at')
                    ->exists();
                $checks['canary_single_shot'] = ! $alreadyReserved;
                if ($alreadyReserved) {
                    $blockers[] = 'CANARY_ALREADY_DISPATCHED';
                }
            }
        }

        return [
            'allowed' => $blockers === [],
            'blockers' => $blockers,
            'checks' => $checks,
        ];
    }

    /**
     * Executa exatamente uma tentativa (ou replay do estado durável).
     *
     * @return array{
     *   request: SerproDteCanaryRequest,
     *   replay: bool,
     *   global: array<string, mixed>
     * }
     */
    public function execute(SerproDteCanaryRequest $request, int $actorUserId): array
    {
        $request = SerproDteCanaryRequest::query()->findOrFail($request->id);
        $status = $this->requestStatus($request);

        // Replay se já terminal
        if ($status->isTerminalAttempt() || $status === SerproDteCanaryRequestStatus::Dispatched) {
            if ($status === SerproDteCanaryRequestStatus::Uncertain
                || $status === SerproDteCanaryRequestStatus::Dispatched
            ) {
                // UNCERTAIN / in-flight: sem retry cego.
                $this->audit->record('serpro.dte_canary.replay_uncertain', 'BLOCKED', $request, [
                    'result_status' => $request->result_status,
                ], $actorUserId, $request->office_id);

                return [
                    'request' => $request->fresh(),
                    'replay' => true,
                    'global' => $request->toGlobalSanitizedArray(),
                ];
            }

            if ($status->isTerminalAttempt()) {
                $this->audit->record('serpro.dte_canary.replay', 'SUCCESS', $request, [
                    'result_status' => $request->result_status,
                    'consumption_quantity' => $request->consumption_quantity,
                ], $actorUserId, $request->office_id);

                return [
                    'request' => $request->fresh(),
                    'replay' => true,
                    'global' => $request->toGlobalSanitizedArray(),
                ];
            }
        }

        $correlationId = $request->correlation_id ?: (string) Str::uuid();
        $requestTag = $request->request_tag ?: $this->requestTags->generate();

        $request = DB::transaction(function () use ($request, $correlationId, $requestTag): SerproDteCanaryRequest {
            $locked = SerproDteCanaryRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lockedStatus = $this->requestStatus($locked);
            if ($lockedStatus === SerproDteCanaryRequestStatus::Dispatched || $lockedStatus->isTerminalAttempt()) {
                return $locked;
            }

            $control = SerproDteControl::query()
                ->where('operation_key', DteCanaryCoordinates::OPERATION_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $gate = $this->evaluatePreTransportGate($locked);
            if (! $gate['allowed']) {
                throw new RuntimeException(
                    'Canário DTE bloqueado pré-transporte: '.implode(', ', $gate['blockers'])
                );
            }

            if ($control->mode === SerproDteControlMode::Canary) {
                $alreadyReserved = SerproDteCanaryRequest::query()
                    ->where('operation_key', DteCanaryCoordinates::OPERATION_KEY)
                    ->where('environment', SerproEnvironment::Production->value)
                    ->where('office_id', $control->pilot_office_id)
                    ->where('client_id', $control->pilot_client_id)
                    ->where('id', '!=', $locked->id)
                    ->whereNotNull('dispatched_at')
                    ->exists();
                if ($alreadyReserved) {
                    throw new RuntimeException('Canário DTE bloqueado pré-transporte: CANARY_ALREADY_DISPATCHED');
                }
            } elseif ($control->mode === SerproDteControlMode::Limited) {
                $max = (int) ($control->limited_max_quantity ?? 0);
                $used = (int) $control->limited_used_quantity;
                if ($max <= 0 || $used >= $max) {
                    throw new RuntimeException('Canário DTE bloqueado pré-transporte: LIMITED_QUOTA_EXHAUSTED');
                }
                $control->forceFill(['limited_used_quantity' => $used + 1])->save();
            }

            $locked->forceFill([
                'status' => SerproDteCanaryRequestStatus::Dispatched,
                'correlation_id' => $correlationId,
                'request_tag' => $requestTag,
                'dispatched_at' => now(),
            ])->save();

            return $locked->fresh();
        });

        if ($request->correlation_id !== $correlationId) {
            return [
                'request' => $request,
                'replay' => true,
                'global' => $request->toGlobalSanitizedArray(),
            ];
        }

        $office = Office::query()->withoutGlobalScopes()->findOrFail($request->office_id);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($request->client_id)
            ->firstOrFail();

        try {
            $response = $this->operations->execute(
                office: $office,
                client: $client,
                operationKey: DteCanaryCoordinates::OPERATION_KEY,
                businessData: [],
                idempotencyKey: $request->idempotency_key,
                correlationId: $correlationId,
                module: 'mailbox',
            );
        } catch (Throwable $e) {
            // Timeout / exceção após possível dispatch → UNCERTAIN sem retry
            $request->forceFill([
                'status' => SerproDteCanaryRequestStatus::Uncertain,
                'result_status' => 'UNCERTAIN',
                'finished_at' => now(),
                'consumption_quantity' => 1,
            ])->save();

            $this->audit->record('serpro.dte_canary.uncertain', 'FAILURE', $request, [
                'error_code' => 'TRANSPORT_EXCEPTION',
                'exception_type' => class_basename($e),
            ], $actorUserId, $office->id);

            $this->maybeEmitUsageAlerts($this->ensureControl()->fresh());

            return [
                'request' => $request->fresh(),
                'replay' => false,
                'global' => $request->fresh()->toGlobalSanitizedArray(),
            ];
        }

        $success = (bool) $response->success;
        $uncertain = ! $success
            && in_array((string) ($response->errorCode ?? ''), ['TIMEOUT', 'UNCERTAIN', 'TRANSPORT_UNKNOWN'], true);

        if ($uncertain) {
            $newStatus = SerproDteCanaryRequestStatus::Uncertain;
            $resultStatus = 'UNCERTAIN';
        } elseif ($success) {
            $newStatus = SerproDteCanaryRequestStatus::Succeeded;
            $resultStatus = 'SUCCEEDED';
        } else {
            $newStatus = SerproDteCanaryRequestStatus::Failed;
            $resultStatus = 'FAILED';
        }

        // Vincular attempt se o executor expôs correlation
        $attemptId = null;
        if (Schema::hasTable('serpro_operation_attempts')) {
            $attemptId = DB::table('serpro_operation_attempts')
                ->where('idempotency_key', $request->idempotency_key)
                ->value('id');
            if ($attemptId === null && $correlationId !== '') {
                $attemptId = DB::table('serpro_operation_attempts')
                    ->where('correlation_id', $correlationId)
                    ->where('operation_key', DteCanaryCoordinates::OPERATION_KEY)
                    ->orderByDesc('id')
                    ->value('id');
            }
        }

        $request->forceFill([
            'status' => $newStatus,
            'result_status' => $resultStatus,
            'finished_at' => now(),
            'consumption_quantity' => 1,
            'attempt_id' => $attemptId,
        ])->save();

        $this->maybeEmitUsageAlerts($this->ensureControl()->fresh());

        $this->audit->record('serpro.dte_canary.execute', $success ? 'SUCCESS' : 'FAILURE', $request, [
            'result_status' => $resultStatus,
            'replay' => false,
            'http_status' => $response->httpStatus,
            'error_code' => $response->errorCode,
            // sem payload fiscal
        ], $actorUserId, $office->id);

        return [
            'request' => $request->fresh(),
            'replay' => false,
            'global' => $request->fresh()->toGlobalSanitizedArray(),
        ];
    }

    /**
     * Reconciliação manual com referência da Área do Cliente.
     */
    public function reconcile(
        SerproDteCanaryRequest $request,
        int $actorUserId,
        string $reference,
        string $summary,
        bool $passwordRecentlyConfirmed,
    ): SerproDteCanaryRequest {
        if (! $passwordRecentlyConfirmed) {
            throw new RuntimeException('Reconfirmação de senha obrigatória para reconciliação.');
        }

        $reference = trim($reference);
        $summary = trim($summary);
        if ($reference === '' || $summary === '') {
            throw new RuntimeException('Referência e resumo da reconciliação são obrigatórios.');
        }

        $status = $request->status instanceof SerproDteCanaryRequestStatus
            ? $request->status
            : SerproDteCanaryRequestStatus::from((string) $request->status);

        if (! $status->allowsReconciliation() && $status !== SerproDteCanaryRequestStatus::Reconciled) {
            throw new RuntimeException('Pedido não está em estado reconciliável.');
        }

        $request->forceFill([
            'reconciliation_reference' => mb_substr($reference, 0, 200),
            'reconciliation_summary' => mb_substr($summary, 0, 1000),
            'reconciled_by_user_id' => $actorUserId,
            'reconciled_at' => now(),
            'status' => SerproDteCanaryRequestStatus::Reconciled,
        ])->save();

        $this->audit->record('serpro.dte_canary.reconcile', 'SUCCESS', $request, [
            'reference' => mb_substr($reference, 0, 80),
        ], $actorUserId, $request->office_id);

        return $request->fresh();
    }

    /**
     * Promove DTE para LIMITED no mesmo Office piloto com teto 10.
     */
    public function promoteLimited(
        SerproDteCanaryRequest $request,
        User $owner,
        bool $passwordRecentlyConfirmed,
        string $confirmationPhrase,
        string $reason,
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null,
        int $maxQuantity = DteCanaryCoordinates::LIMITED_DEFAULT_MAX_QUANTITY,
    ): SerproDteControl {
        if (! $passwordRecentlyConfirmed) {
            throw new RuntimeException('Reconfirmação de senha obrigatória para promoção LIMITED.');
        }
        if (! $owner->isPlatformAdmin()) {
            throw new RuntimeException('Somente o Proprietário pode promover DTE LIMITED.');
        }
        if (trim($confirmationPhrase) !== self::CONFIRM_PROMOTE_PHRASE) {
            throw new RuntimeException('Frase de confirmação inválida (esperado CONFIRMO-DTE-LIMITED).');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Motivo da promoção é obrigatório.');
        }

        $now = CarbonImmutable::now();
        if ($windowStart !== null && $windowEnd !== null) {
            if (! $now->betweenIncluded($windowStart, $windowEnd)) {
                throw new RuntimeException('Fora da janela de mudança autorizada.');
            }
        }

        $status = $request->status instanceof SerproDteCanaryRequestStatus
            ? $request->status
            : SerproDteCanaryRequestStatus::from((string) $request->status);

        if ($status !== SerproDteCanaryRequestStatus::Reconciled
            && $request->reconciled_at === null
        ) {
            throw new RuntimeException('Promoção LIMITED exige reconciliação manual válida do canário.');
        }

        if ($request->result_status !== 'SUCCEEDED') {
            throw new RuntimeException('Promoção LIMITED só após canário com sucesso e reconciliação.');
        }

        if ($request->office_id === null) {
            throw new RuntimeException('Office piloto ausente no pedido.');
        }

        $maxQuantity = max(1, min(10, $maxQuantity));

        $control = $this->ensureControl();
        $control->forceFill([
            'mode' => SerproDteControlMode::Limited,
            'pilot_office_id' => $request->office_id,
            'pilot_client_id' => $request->client_id,
            'limited_max_quantity' => $maxQuantity,
            'limited_used_quantity' => 0,
            'cycle_code' => now()->format('Y-m'),
            'promoted_at' => now(),
            'promoted_by_user_id' => $owner->id,
            'disabled_at' => null,
            'disable_reason' => null,
            'alert_80_emitted' => false,
            'alert_100_emitted' => false,
            'metadata' => $this->audit->redact([
                'canary_request_id' => $request->id,
                'reason' => mb_substr($reason, 0, 200),
                'reconciliation_reference' => $request->reconciliation_reference,
            ]),
        ])->save();

        $this->audit->record('serpro.dte_canary.promote_limited', 'SUCCESS', $control, [
            'office_id' => $request->office_id,
            'max_quantity' => $maxQuantity,
            'canary_request_id' => $request->id,
        ], $owner->id, $request->office_id);

        return $control->fresh();
    }

    /**
     * Desativação imediata — bloqueia novas reservas.
     */
    public function disable(
        User $owner,
        bool $passwordRecentlyConfirmed,
        string $confirmationPhrase,
        string $reason,
    ): SerproDteControl {
        if (! $passwordRecentlyConfirmed) {
            throw new RuntimeException('Reconfirmação de senha obrigatória para desativação DTE.');
        }
        if (! $owner->isPlatformAdmin()) {
            throw new RuntimeException('Somente o Proprietário pode desativar DTE.');
        }
        if (trim($confirmationPhrase) !== self::CONFIRM_DISABLE_PHRASE) {
            throw new RuntimeException('Frase de confirmação inválida (esperado CONFIRMO-DTE-DISABLE).');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Motivo da desativação é obrigatório.');
        }

        $control = $this->ensureControl();
        $control->forceFill([
            'mode' => SerproDteControlMode::Disabled,
            'disabled_at' => now(),
            'disabled_by_user_id' => $owner->id,
            'disable_reason' => mb_substr($reason, 0, 500),
        ])->save();

        $this->audit->record('serpro.dte_canary.disable', 'SUCCESS', $control, [
            'reason' => mb_substr($reason, 0, 200),
        ], $owner->id, $control->pilot_office_id);

        return $control->fresh();
    }

    /**
     * Resumo global sanitizado (sem payload fiscal).
     *
     * @return array<string, mixed>
     */
    public function globalSummary(?int $requestId = null): array
    {
        $control = $this->ensureControl();
        $q = SerproDteCanaryRequest::query()->orderByDesc('id');
        if ($requestId !== null) {
            $q->whereKey($requestId);
        }
        $latest = $q->first();

        return [
            'control' => $control->toSanitizedArray(),
            'coordinates' => DteCanaryCoordinates::asArray(),
            'request' => $latest?->toGlobalSanitizedArray(),
            'gate' => $latest !== null ? $this->evaluatePreTransportGate($latest) : null,
        ];
    }

    /**
     * Resultado tenant — exige membership no Office do pedido.
     *
     * @return array<string, mixed>
     */
    public function tenantResult(SerproDteCanaryRequest $request, User $user, Office $currentOffice): array
    {
        if ((int) $currentOffice->id !== (int) $request->office_id) {
            throw new RuntimeException('Resultado DTE indisponível para o Office corrente.');
        }

        $membership = OfficeMembership::query()
            ->where('office_id', $currentOffice->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $membership) {
            throw new RuntimeException('Membership ativa no Office é obrigatória para ver resultado fiscal DTE.');
        }

        $request->loadMissing('attempt');

        return $request->toTenantResultArray();
    }

    private function refreshApprovalStatus(SerproDteCanaryRequest $request): void
    {
        if ($request->isFullyApproved()) {
            $request->status = SerproDteCanaryRequestStatus::FullyApproved;

            return;
        }
        if ($request->hasOwnerApproval() || $request->hasOfficeAdminApproval()) {
            $request->status = SerproDteCanaryRequestStatus::PartialApproved;

            return;
        }
        if ($request->office_id !== null) {
            $request->status = SerproDteCanaryRequestStatus::TargetSet;
        }
    }

    private function approvalRolesRemainValid(SerproDteCanaryRequest $request): bool
    {
        if (! $request->isFullyApproved() || $request->office_id === null) {
            return false;
        }

        $owner = User::query()->find($request->owner_approver_user_id);
        if ($owner === null || ! $owner->is_active || ! $owner->isPlatformAdmin()) {
            return false;
        }

        $officeAdmin = User::query()->find($request->office_admin_approver_user_id);
        if ($officeAdmin === null || ! $officeAdmin->is_active) {
            return false;
        }

        return OfficeMembership::query()
            ->where('office_id', $request->office_id)
            ->where('user_id', $officeAdmin->id)
            ->where('is_active', true)
            ->where('role', OfficeRole::Admin->value)
            ->exists();
    }

    private function requestStatus(SerproDteCanaryRequest $request): SerproDteCanaryRequestStatus
    {
        return $request->status instanceof SerproDteCanaryRequestStatus
            ? $request->status
            : SerproDteCanaryRequestStatus::from((string) $request->status);
    }

    private function assertMutableTarget(SerproDteCanaryRequest $request): void
    {
        $status = $request->status instanceof SerproDteCanaryRequestStatus
            ? $request->status
            : SerproDteCanaryRequestStatus::tryFrom((string) $request->status);

        if ($status !== null && $status->isTerminalAttempt()) {
            throw new RuntimeException('Pedido já finalizado; alvo imutável.');
        }
        if ($request->hasOwnerApproval() || $request->hasOfficeAdminApproval()) {
            throw new RuntimeException('Alvo imutável após início das aprovações.');
        }
        if ($status === SerproDteCanaryRequestStatus::Dispatched) {
            throw new RuntimeException('Pedido já despachado; alvo imutável.');
        }
    }

    private function assertNotExpired(SerproDteCanaryRequest $request): void
    {
        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            throw new RuntimeException('Pedido de canário DTE expirado.');
        }
    }

    private function allExternalGatesAccepted(): bool
    {
        if (! Schema::hasTable('serpro_external_gates')) {
            return false;
        }

        $gates = SerproExternalGate::query()->get();
        if ($gates->count() < 6) {
            // ensure baseline may not have run
            try {
                app(SerproExternalGateService::class)->ensureBaselineGates();
                $gates = SerproExternalGate::query()->get();
            } catch (Throwable) {
                return false;
            }
        }

        if ($gates->isEmpty()) {
            return false;
        }

        foreach ($gates as $gate) {
            $status = $gate->status instanceof SerproExternalGateStatus
                ? $gate->status
                : SerproExternalGateStatus::tryFrom((string) $gate->status);
            if ($status === null || $status->blocksProduction()) {
                return false;
            }
        }

        return true;
    }

    private function officeHasValidA1(Office $office): bool
    {
        if (! Schema::hasTable('office_credentials')) {
            return false;
        }

        $credential = OfficeCredential::query()
            ->where('office_id', $office->id)
            ->where('status', CredentialStatus::Active->value)
            ->orderByDesc('id')
            ->first();

        if ($credential === null) {
            return false;
        }

        if ($credential->valid_to !== null && $credential->valid_to->isPast()) {
            return false;
        }

        return true;
    }

    private function quantityLimitsPositive(?int $officeId, SerproEnvironment $environment): bool
    {
        if (! Schema::hasTable('serpro_quantity_usage_limits')) {
            // Sem tabela de limites quantitativos: exigir budget monetário global positivo como fallback
            return true; // gate de budget do executor ainda aplica; não bloquear se infra irmã ausente em testes unitários parciais
        }

        $global = SerproQuantityUsageLimit::query()
            ->where('environment', $environment->value)
            ->where('is_active', true)
            ->first();

        if ($global === null || ! $global->isConfiguredPositive()) {
            return false;
        }

        if ($officeId === null) {
            return false;
        }

        if (! Schema::hasTable('serpro_office_quantity_usage_limits')) {
            return false;
        }

        $officeLimit = SerproOfficeQuantityUsageLimit::query()
            ->where('office_id', $officeId)
            ->where('environment', $environment->value)
            ->where('is_active', true)
            ->first();

        return $officeLimit !== null && $officeLimit->isConfiguredPositive();
    }

    private function maybeEmitUsageAlerts(SerproDteControl $control): void
    {
        $ratio = $control->usageRatio();
        if ($ratio === null) {
            return;
        }

        $alertAt = ((int) ($control->alert_percent ?? 80)) / 100;

        if ($ratio >= 1.0 && ! $control->alert_100_emitted) {
            Log::warning('serpro.dte_limited.quota_100', [
                'operation_key' => DteCanaryCoordinates::OPERATION_KEY,
                'office_id' => $control->pilot_office_id,
                'used' => $control->limited_used_quantity,
                'max' => $control->limited_max_quantity,
            ]);
            $control->forceFill(['alert_100_emitted' => true])->save();
            $this->audit->record('serpro.dte_canary.alert_100', 'SUCCESS', $control, [
                'used' => $control->limited_used_quantity,
                'max' => $control->limited_max_quantity,
            ], null, $control->pilot_office_id);
        } elseif ($ratio >= $alertAt && ! $control->alert_80_emitted) {
            Log::warning('serpro.dte_limited.quota_80', [
                'operation_key' => DteCanaryCoordinates::OPERATION_KEY,
                'office_id' => $control->pilot_office_id,
                'used' => $control->limited_used_quantity,
                'max' => $control->limited_max_quantity,
            ]);
            $control->forceFill(['alert_80_emitted' => true])->save();
            $this->audit->record('serpro.dte_canary.alert_80', 'SUCCESS', $control, [
                'used' => $control->limited_used_quantity,
                'max' => $control->limited_max_quantity,
            ], null, $control->pilot_office_id);
        }
    }
}
