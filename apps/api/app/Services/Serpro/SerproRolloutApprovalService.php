<?php

namespace App\Services\Serpro;

use App\Enums\OfficeRole;
use App\Enums\SerproApprovalPolicy;
use App\Enums\SerproEnvironment;
use App\Models\OfficeMembership;
use App\Models\SerproRolloutApproval;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aprovações sensíveis de plataforma SERPRO com política por ação:
 * - OWNER_CONFIRMATION: proprietário único (senha recente + frase + motivo + janela)
 * - DUAL_ROLE: duas pessoas com papéis distintos (canário / promoção)
 *
 * Persistente em serpro_rollout_approvals (não Redis). CLI/job não fabricam aprovação.
 */
final class SerproRolloutApprovalService
{
    public const ACTION_KILL_SWITCH_OFF = 'KILL_SWITCH_OFF';

    public const ACTION_KILL_SWITCH_SOLUTION_OFF = 'KILL_SWITCH_SOLUTION_OFF';

    public const ACTION_CONTRACT_ACTIVATE = 'CONTRACT_ACTIVATE';

    public const ACTION_ROLLOUT_PROMOTE = 'ROLLOUT_PROMOTE';

    public const ACTION_CREDENTIAL_CUTOVER = 'CREDENTIAL_CUTOVER';

    /** Promoção FREE_SMOKE_OK (escada gratuita — sem canário faturável). */
    public const ACTION_FREE_SMOKE_PROMOTE = 'FREE_SMOKE_PROMOTE';

    /** Canário faturável delimitado (dual approval + teto unitário). */
    public const ACTION_BILLABLE_CANARY = 'BILLABLE_CANARY';

    /** @var list<string> */
    private const OWNER_CONFIRMATION_ACTIONS = [
        self::ACTION_KILL_SWITCH_OFF,
        self::ACTION_KILL_SWITCH_SOLUTION_OFF,
        self::ACTION_CONTRACT_ACTIVATE,
        self::ACTION_CREDENTIAL_CUTOVER,
    ];

    /** @var list<string> */
    private const DUAL_ROLE_ACTIONS = [
        self::ACTION_ROLLOUT_PROMOTE,
        self::ACTION_BILLABLE_CANARY,
        self::ACTION_FREE_SMOKE_PROMOTE,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SerproKillSwitchService $killSwitch,
    ) {}

    public function policyForAction(string $action): SerproApprovalPolicy
    {
        $action = strtoupper($action);

        if (in_array($action, self::OWNER_CONFIRMATION_ACTIONS, true)) {
            return SerproApprovalPolicy::OwnerConfirmation;
        }

        if (in_array($action, self::DUAL_ROLE_ACTIONS, true)) {
            return SerproApprovalPolicy::DualRole;
        }

        // Ações desconhecidas: fail-closed dual (nunca OWNER por omissão).
        return SerproApprovalPolicy::DualRole;
    }

    public function expectedConfirmationPhrase(string $action): string
    {
        return 'CONFIRMO-'.strtoupper($action);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function request(
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $reason,
        int $requestedByUserId,
        ?SerproEnvironment $environment = null,
        ?int $officeId = null,
        array $context = [],
        int $ttlHours = 24,
        ?CarbonImmutable $changeWindowStart = null,
        ?CarbonImmutable $changeWindowEnd = null,
        bool $fromHttp = true,
    ): SerproRolloutApproval {
        if (! $fromHttp) {
            throw new RuntimeException(
                'Aprovação humana SERPRO só pode ser criada via API HTTP autenticada; CLI/job não fabricam confirmação.'
            );
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Motivo da aprovação é obrigatório.');
        }

        $action = strtoupper($action);
        $policy = $this->policyForAction($action);

        $env = $environment
            ?? SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;

        if ($policy === SerproApprovalPolicy::OwnerConfirmation) {
            $this->assertValidChangeWindow($changeWindowStart, $changeWindowEnd);
        }

        if ($policy === SerproApprovalPolicy::DualRole
            && in_array($action, [self::ACTION_BILLABLE_CANARY], true)
            && ($officeId === null || $officeId <= 0)
        ) {
            throw new RuntimeException('Canário faturável exige office_id do Office delimitado (não injetado pelo cliente no escopo).');
        }

        $approval = SerproRolloutApproval::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'approval_policy' => $policy,
            'environment' => $env,
            'office_id' => $officeId,
            'status' => 'PENDING',
            'reason' => mb_substr($reason, 0, 500),
            'confirmation_phrase' => $policy === SerproApprovalPolicy::OwnerConfirmation
                ? $this->expectedConfirmationPhrase($action)
                : null,
            'requested_by_user_id' => $requestedByUserId,
            'expires_at' => CarbonImmutable::now()->addHours(max(1, $ttlHours)),
            'change_window_start' => $changeWindowStart,
            'change_window_end' => $changeWindowEnd,
            'context' => $this->audit->redact($context),
        ]);

        $this->audit->record('serpro.rollout.request', 'SUCCESS', $approval, [
            'action' => $approval->action,
            'approval_policy' => $policy->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'environment' => $env->value,
        ], $requestedByUserId, $officeId);

        return $approval;
    }

    /**
     * Registra confirmação (OWNER) ou um dos olhos (DUAL_ROLE).
     *
     * @return array{approval: SerproRolloutApproval, executed: bool}
     */
    public function approve(
        SerproRolloutApproval $approval,
        int $approverUserId,
        bool $passwordRecentlyConfirmed,
        ?string $reason = null,
        ?string $confirmationPhrase = null,
        ?CarbonImmutable $changeWindowStart = null,
        ?CarbonImmutable $changeWindowEnd = null,
        bool $fromHttp = true,
    ): array {
        if (! $fromHttp) {
            throw new RuntimeException(
                'Confirmação humana SERPRO só pode ser registrada via API HTTP; CLI/job não fabricam ator/timestamp.'
            );
        }

        if (! $passwordRecentlyConfirmed) {
            throw new RuntimeException('Aprovação de rollout SERPRO exige reconfirmação de senha recente.');
        }

        if (in_array($approval->status, ['EXECUTED', 'REJECTED', 'EXPIRED'], true)) {
            throw new RuntimeException('Aprovação em estado final: '.$approval->status);
        }

        if ($approval->isExpired()) {
            $approval->forceFill(['status' => 'EXPIRED'])->save();
            throw new RuntimeException('Aprovação expirada.');
        }

        $user = User::query()->find($approverUserId);
        if ($user === null || ! $user->is_active) {
            throw new RuntimeException('Aprovador inválido ou inativo.');
        }

        return match ($approval->policy()) {
            SerproApprovalPolicy::OwnerConfirmation => $this->approveOwner(
                $approval,
                $user,
                $reason,
                $confirmationPhrase,
                $changeWindowStart,
                $changeWindowEnd,
            ),
            SerproApprovalPolicy::DualRole => $this->approveDualRole(
                $approval,
                $user,
                $reason,
            ),
        };
    }

    public function reject(
        SerproRolloutApproval $approval,
        int $userId,
        string $reason,
    ): SerproRolloutApproval {
        $approval->forceFill([
            'status' => 'REJECTED',
            'reason' => mb_substr($reason, 0, 500),
        ])->save();

        $this->audit->record('serpro.rollout.reject', 'SUCCESS', $approval, [
            'action' => $approval->action,
            'reason' => mb_substr($reason, 0, 200),
        ], $userId, $approval->office_id);

        return $approval->refresh();
    }

    /**
     * Trava e valida aprovação OWNER consumível (sem marcar EXECUTED).
     * Caller deve chamar {@see markOwnerApprovalExecuted} no mesmo transaction após sucesso.
     * Reuso, expiração ou escopo divergente bloqueiam.
     */
    public function claimOwnerApproval(
        string $action,
        string $subjectType,
        int $subjectId,
        ?SerproEnvironment $environment,
        int $actorUserId,
        ?int $approvalId = null,
    ): SerproRolloutApproval {
        $action = strtoupper($action);
        if ($this->policyForAction($action) !== SerproApprovalPolicy::OwnerConfirmation) {
            throw new RuntimeException(
                "Ação {$action} não usa política OWNER_CONFIRMATION; não pode ser consumida como autorização singleton."
            );
        }

        $q = SerproRolloutApproval::query()
            ->where('action', $action)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('approval_policy', SerproApprovalPolicy::OwnerConfirmation->value)
            ->where('status', 'APPROVED')
            ->whereNull('executed_at')
            ->lockForUpdate()
            ->orderByDesc('id');

        if ($approvalId !== null) {
            $q->whereKey($approvalId);
        }

        if ($environment !== null) {
            $q->where('environment', $environment->value);
        }

        /** @var SerproRolloutApproval|null $locked */
        $locked = $q->first();
        if ($locked === null) {
            throw new RuntimeException(
                'Operação bloqueada: ausência de confirmação OWNER vigente e vinculada ao recurso/ação/ambiente.'
            );
        }

        if ($locked->isExpired()) {
            $locked->forceFill(['status' => 'EXPIRED'])->save();
            throw new RuntimeException('Aprovação expirada; operação bloqueada.');
        }

        if (! $locked->isChangeWindowActive()) {
            throw new RuntimeException('Janela de mudança da confirmação não está vigente.');
        }

        if (! $locked->isFullyApproved()) {
            throw new RuntimeException('Confirmação do proprietário incompleta.');
        }

        if ((int) $locked->first_approver_user_id !== $actorUserId) {
            throw new RuntimeException(
                'Consumo da confirmação exige o mesmo proprietário que a registrou.'
            );
        }

        return $locked;
    }

    public function markOwnerApprovalExecuted(
        SerproRolloutApproval $approval,
        int $actorUserId,
    ): SerproRolloutApproval {
        if ($approval->executed_at !== null || $approval->status === 'EXECUTED') {
            throw new RuntimeException('Aprovação já consumida; reuso bloqueado.');
        }

        $approval->forceFill([
            'status' => 'EXECUTED',
            'executed_at' => now(),
        ])->save();

        $this->audit->record('serpro.rollout.consumed', 'SUCCESS', $approval, [
            'action' => $approval->action,
            'subject_type' => $approval->subject_type,
            'subject_id' => $approval->subject_id,
        ], $actorUserId, $approval->office_id);

        return $approval->refresh();
    }

    /**
     * Consome atomicamente (claim + mark) — para fluxos que já estão no ponto de sucesso.
     */
    public function consumeOwnerApproval(
        string $action,
        string $subjectType,
        int $subjectId,
        ?SerproEnvironment $environment,
        int $actorUserId,
        ?int $approvalId = null,
    ): SerproRolloutApproval {
        return DB::transaction(function () use (
            $action,
            $subjectType,
            $subjectId,
            $environment,
            $actorUserId,
            $approvalId,
        ): SerproRolloutApproval {
            $locked = $this->claimOwnerApproval(
                $action,
                $subjectType,
                $subjectId,
                $environment,
                $actorUserId,
                $approvalId,
            );

            return $this->markOwnerApprovalExecuted($locked, $actorUserId);
        });
    }

    /**
     * Localiza aprovação APPROVED consumível sem consumir (validação prévia).
     */
    public function findConsumableOwnerApproval(
        string $action,
        string $subjectType,
        int $subjectId,
        ?SerproEnvironment $environment = null,
        ?int $approvalId = null,
    ): ?SerproRolloutApproval {
        $q = SerproRolloutApproval::query()
            ->where('action', strtoupper($action))
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('approval_policy', SerproApprovalPolicy::OwnerConfirmation->value)
            ->where('status', 'APPROVED')
            ->whereNull('executed_at')
            ->orderByDesc('id');

        if ($approvalId !== null) {
            $q->whereKey($approvalId);
        }
        if ($environment !== null) {
            $q->where('environment', $environment->value);
        }

        $row = $q->first();
        if ($row === null || $row->isExpired() || ! $row->isChangeWindowActive() || ! $row->isFullyApproved()) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSanitized(?string $status = null, int $limit = 50): array
    {
        $q = SerproRolloutApproval::query()->orderByDesc('id')->limit(min(100, max(1, $limit)));
        if ($status !== null && $status !== '') {
            $q->where('status', strtoupper($status));
        }

        return $q->get()->map(fn (SerproRolloutApproval $a) => $this->toSanitized($a))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitized(SerproRolloutApproval $approval): array
    {
        $policy = $approval->policy();

        return [
            'id' => $approval->id,
            'subject_type' => $approval->subject_type,
            'subject_id' => $approval->subject_id,
            'action' => $approval->action,
            'approval_policy' => $policy->value,
            'environment' => $approval->environment instanceof SerproEnvironment
                ? $approval->environment->value
                : (string) $approval->environment,
            'office_id' => $approval->office_id,
            'status' => $approval->status,
            'reason' => $approval->reason,
            // Frase esperada (não é segredo) — UI de confirmação explícita.
            'confirmation_phrase' => $approval->confirmation_phrase,
            'expected_confirmation_phrase' => $policy === SerproApprovalPolicy::OwnerConfirmation
                ? ($approval->confirmation_phrase ?? $this->expectedConfirmationPhrase((string) $approval->action))
                : null,
            'requested_by_user_id' => $approval->requested_by_user_id,
            'first_approver_user_id' => $approval->first_approver_user_id,
            'second_approver_user_id' => $approval->second_approver_user_id,
            'first_approved_at' => $approval->first_approved_at?->toIso8601String(),
            'second_approved_at' => $approval->second_approved_at?->toIso8601String(),
            'executed_at' => $approval->executed_at?->toIso8601String(),
            'expires_at' => $approval->expires_at?->toIso8601String(),
            'change_window_start' => $approval->change_window_start?->toIso8601String(),
            'change_window_end' => $approval->change_window_end?->toIso8601String(),
            'fully_approved' => $approval->isFullyApproved(),
            'context' => is_array($approval->context) ? $this->audit->redact($approval->context) : null,
        ];
    }

    /**
     * @return array{approval: SerproRolloutApproval, executed: bool}
     */
    private function approveOwner(
        SerproRolloutApproval $approval,
        User $user,
        ?string $reason,
        ?string $confirmationPhrase,
        ?CarbonImmutable $changeWindowStart,
        ?CarbonImmutable $changeWindowEnd,
    ): array {
        if (! $user->isPlatformAdmin()) {
            throw new RuntimeException('Confirmação OWNER exige o Proprietário PLATFORM_ADMIN da instalação.');
        }

        // Nunca PARTIAL: validação total antes de gravar APPROVED.
        $expected = (string) ($approval->confirmation_phrase
            ?: $this->expectedConfirmationPhrase((string) $approval->action));
        $provided = trim((string) $confirmationPhrase);
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new RuntimeException('Frase de confirmação inválida ou divergente da operação.');
        }

        $reasonFinal = trim((string) ($reason ?? $approval->reason ?? ''));
        if ($reasonFinal === '') {
            throw new RuntimeException('Motivo da confirmação é obrigatório.');
        }

        $windowStart = $changeWindowStart ?? $approval->change_window_start;
        $windowEnd = $changeWindowEnd ?? $approval->change_window_end;
        $this->assertValidChangeWindow(
            $windowStart instanceof CarbonImmutable ? $windowStart : ($windowStart !== null ? CarbonImmutable::instance($windowStart) : null),
            $windowEnd instanceof CarbonImmutable ? $windowEnd : ($windowEnd !== null ? CarbonImmutable::instance($windowEnd) : null),
        );

        if ($windowStart === null || $windowEnd === null) {
            throw new RuntimeException('Janela de mudança vigente é obrigatória.');
        }

        $start = $windowStart instanceof CarbonImmutable
            ? $windowStart
            : CarbonImmutable::instance($windowStart);
        $end = $windowEnd instanceof CarbonImmutable
            ? $windowEnd
            : CarbonImmutable::instance($windowEnd);

        if (! CarbonImmutable::now()->betweenIncluded($start, $end)) {
            throw new RuntimeException('Janela de mudança não está vigente neste instante.');
        }

        if ($approval->first_approver_user_id !== null) {
            throw new RuntimeException('Confirmação do proprietário já registrada; reuso bloqueado.');
        }

        return DB::transaction(function () use ($approval, $user, $reasonFinal, $start, $end): array {
            /** @var SerproRolloutApproval $locked */
            $locked = SerproRolloutApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($locked->first_approver_user_id !== null || $locked->status !== 'PENDING') {
                throw new RuntimeException('Confirmação do proprietário já registrada ou estado inválido.');
            }

            $locked->forceFill([
                'first_approver_user_id' => $user->id,
                'first_approved_at' => now(),
                'second_approver_user_id' => null,
                'second_approved_at' => null,
                'status' => 'APPROVED',
                'reason' => mb_substr($reasonFinal, 0, 500),
                'change_window_start' => $start,
                'change_window_end' => $end,
            ])->save();

            $this->audit->record('serpro.rollout.owner_confirm', 'SUCCESS', $locked, [
                'action' => $locked->action,
                'approver_user_id' => $user->id,
                'approval_policy' => SerproApprovalPolicy::OwnerConfirmation->value,
            ], $user->id, $locked->office_id);

            $executed = $this->executeIfReady($locked->refresh(), $user->id);

            return ['approval' => $locked->refresh(), 'executed' => $executed];
        });
    }

    /**
     * @return array{approval: SerproRolloutApproval, executed: bool}
     */
    private function approveDualRole(
        SerproRolloutApproval $approval,
        User $user,
        ?string $reason,
    ): array {
        return DB::transaction(function () use ($approval, $user, $reason): array {
            /** @var SerproRolloutApproval $locked */
            $locked = SerproRolloutApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['EXECUTED', 'REJECTED', 'EXPIRED', 'APPROVED'], true)) {
                throw new RuntimeException('Aprovação em estado final: '.$locked->status);
            }

            $role = $this->resolveDualApproverRole($locked, $user);

            if ($locked->first_approver_user_id === null) {
                $locked->forceFill([
                    'first_approver_user_id' => $user->id,
                    'first_approved_at' => now(),
                    'status' => 'PARTIAL',
                    'reason' => $reason !== null ? mb_substr($reason, 0, 500) : $locked->reason,
                    'context' => $this->mergeContext($locked, [
                        'first_approver_role' => $role,
                    ]),
                ])->save();

                $this->audit->record('serpro.rollout.first_approve', 'SUCCESS', $locked, [
                    'action' => $locked->action,
                    'approver_user_id' => $user->id,
                    'approver_role' => $role,
                ], $user->id, $locked->office_id);

                return ['approval' => $locked->refresh(), 'executed' => false];
            }

            if ((int) $locked->first_approver_user_id === (int) $user->id) {
                throw new RuntimeException(
                    'Política dual exige segundo aprovador distinto; a mesma conta não preenche ambos os papéis.'
                );
            }

            if ($locked->second_approver_user_id !== null) {
                throw new RuntimeException('Aprovação dual já possui dois olhos.');
            }

            $firstRole = is_array($locked->context)
                ? (string) ($locked->context['first_approver_role'] ?? '')
                : '';
            $this->assertComplementaryDualRoles($locked, $firstRole, $role);

            $locked->forceFill([
                'second_approver_user_id' => $user->id,
                'second_approved_at' => now(),
                'status' => 'APPROVED',
                'reason' => $reason !== null ? mb_substr($reason, 0, 500) : $locked->reason,
                'context' => $this->mergeContext($locked, [
                    'second_approver_role' => $role,
                ]),
            ])->save();

            $this->audit->record('serpro.rollout.second_approve', 'SUCCESS', $locked, [
                'action' => $locked->action,
                'approver_user_id' => $user->id,
                'approver_role' => $role,
            ], $user->id, $locked->office_id);

            $executed = $this->executeIfReady($locked->refresh(), $user->id);

            return ['approval' => $locked->refresh(), 'executed' => $executed];
        });
    }

    private function resolveDualApproverRole(SerproRolloutApproval $approval, User $user): string
    {
        $isPlatform = $user->isPlatformAdmin();
        $isOfficeAdmin = false;

        if ($approval->office_id !== null) {
            $isOfficeAdmin = OfficeMembership::query()
                ->where('office_id', $approval->office_id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where('role', OfficeRole::Admin->value)
                ->exists();
        }

        // Canário faturável: papéis Proprietário + Office ADMIN.
        // Conta dual (PLATFORM_ADMIN + Office ADMIN) só exerce o papel de Proprietário —
        // nunca o segundo papel OFFICE_ADMIN.
        if ($approval->action === self::ACTION_BILLABLE_CANARY) {
            if ($isPlatform && $isOfficeAdmin) {
                $ctx = is_array($approval->context) ? $approval->context : [];
                $firstRole = (string) ($ctx['first_approver_role'] ?? '');
                if ($firstRole === 'PLATFORM_ADMIN') {
                    throw new RuntimeException(
                        'Conta dual não pode preencher o papel de Office ADMIN no canário faturável.'
                    );
                }

                return 'PLATFORM_ADMIN';
            }
            if ($isPlatform) {
                return 'PLATFORM_ADMIN';
            }
            if ($isOfficeAdmin) {
                return 'OFFICE_ADMIN';
            }

            throw new RuntimeException(
                'Canário faturável exige Proprietário PLATFORM_ADMIN ou Office ADMIN ativo do Office delimitado.'
            );
        }

        // ROLLOUT_PROMOTE / demais dual: exige PLATFORM_ADMIN distinto.
        if (! $isPlatform) {
            throw new RuntimeException('Aprovação dual de promoção exige PLATFORM_ADMIN.');
        }

        return 'PLATFORM_ADMIN';
    }

    private function assertComplementaryDualRoles(
        SerproRolloutApproval $approval,
        string $firstRole,
        string $secondRole,
    ): void {
        if ($approval->action === self::ACTION_BILLABLE_CANARY) {
            $roles = [$firstRole, $secondRole];
            sort($roles);
            if ($roles !== ['OFFICE_ADMIN', 'PLATFORM_ADMIN']) {
                throw new RuntimeException(
                    'Canário faturável exige um Proprietário e um Office ADMIN distintos do Office delimitado.'
                );
            }

            // Conta dual já bloqueada por IDs distintos; reforço: se o segundo for a mesma identidade, falhou acima.
            return;
        }

        // Promoções: dois PLATFORM_ADMIN distintos (IDs já checados).
        if ($firstRole !== 'PLATFORM_ADMIN' || $secondRole !== 'PLATFORM_ADMIN') {
            throw new RuntimeException('Promoção dual exige dois PLATFORM_ADMIN distintos.');
        }
    }

    private function executeIfReady(SerproRolloutApproval $approval, int $actorUserId): bool
    {
        if (! $approval->isFullyApproved()) {
            return false;
        }

        // Cutover / activate: caller consome via consumeOwnerApproval (não executa efeito aqui).
        if (in_array($approval->action, [
            self::ACTION_ROLLOUT_PROMOTE,
            self::ACTION_CONTRACT_ACTIVATE,
            self::ACTION_CREDENTIAL_CUTOVER,
            self::ACTION_BILLABLE_CANARY,
            self::ACTION_FREE_SMOKE_PROMOTE,
        ], true)) {
            return false;
        }

        $reason = (string) ($approval->reason ?? 'owner_confirmation');

        match ($approval->action) {
            self::ACTION_KILL_SWITCH_OFF => $this->killSwitch->deactivateGlobal($reason, $actorUserId),
            self::ACTION_KILL_SWITCH_SOLUTION_OFF => $this->executeSolutionOff($approval, $reason, $actorUserId),
            default => null,
        };

        $approval->forceFill([
            'status' => 'EXECUTED',
            'executed_at' => now(),
        ])->save();

        $this->audit->record('serpro.rollout.executed', 'SUCCESS', $approval, [
            'action' => $approval->action,
            'approval_policy' => $approval->policy()->value,
        ], $actorUserId, $approval->office_id);

        return true;
    }

    private function executeSolutionOff(SerproRolloutApproval $approval, string $reason, int $actorUserId): void
    {
        $solution = is_array($approval->context) ? ($approval->context['solution'] ?? null) : null;
        if (! is_string($solution) || $solution === '') {
            throw new RuntimeException('Aprovação de solution kill-off sem código de solução.');
        }
        $this->killSwitch->deactivateSolution($solution, $reason, $actorUserId);
    }

    private function assertValidChangeWindow(
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
    ): void {
        if ($start === null || $end === null) {
            throw new RuntimeException('Janela de mudança (início e fim) é obrigatória para confirmação do proprietário.');
        }
        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('Janela de mudança inválida: fim deve ser posterior ao início.');
        }
        $maxHours = max(1, (int) config('serpro.owner_confirmation.max_window_hours', 48));
        if ($start->diffInHours($end) > $maxHours) {
            throw new RuntimeException("Janela de mudança excede o máximo de {$maxHours} horas.");
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeContext(SerproRolloutApproval $approval, array $extra): array
    {
        $base = is_array($approval->context) ? $approval->context : [];

        return $this->audit->redact(array_merge($base, $extra));
    }
}
