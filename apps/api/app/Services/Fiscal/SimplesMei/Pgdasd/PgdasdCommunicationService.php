<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDispatchStatus;
use App\Enums\CommunicationExecutionMode;
use App\Enums\TenantPermission;
use App\Exceptions\CommunicationOperationException;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientCommunicationPreference;
use App\Models\ClientContact;
use App\Models\CommunicationAutomationPolicy;
use App\Models\PgdasdArtifact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Communication\Automation\CommunicationRecipientResolver;
use App\Services\Communication\Automation\FiscalCommunicationAutomationService;
use App\Services\Fiscal\Dctfweb\DctfwebPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Preferências, prévia, rastreio e fila de envio.
 * Provider externo fail-closed (`fiscal_monitoring.communication.provider_enabled`).
 */
final class PgdasdCommunicationService
{
    public const MODULE = 'simples_mei';

    public const SUBMODULE = 'pgdasd';

    public const BATCH_LIMIT = 100;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantAuthorization $authorization,
        private readonly string $submoduleKey = self::SUBMODULE,
        private readonly string $auditPrefix = 'pgdasd.communication',
        private readonly string $moduleKey = self::MODULE,
    ) {}

    public function submoduleKey(): string
    {
        return $this->submoduleKey;
    }

    public function moduleKey(): string
    {
        return $this->moduleKey;
    }

    /**
     * Leitura sem efeito colateral: preferência ausente vira default somente em memória.
     */
    public function getPreferences(Tenant $tenant, Client $client): ClientCommunicationPreference
    {
        $this->assertClient($tenant, $client);

        return ClientCommunicationPreference::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('module_key', $this->moduleKey)
            ->where('submodule_key', $this->submoduleKey)
            ->first() ?? new ClientCommunicationPreference([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'module_key' => $this->moduleKey,
                'submodule_key' => $this->submoduleKey,
                'automatic_requested' => false,
                'email_enabled' => false,
                'whatsapp_enabled' => false,
                // Default transitório: primeira mutação espera 0 e persiste versão 1.
                'lock_version' => 0,
            ]);
    }

    /**
     * Resumo reutilizável por carteira/linha sem persistir defaults.
     *
     * @return array<string, mixed>
     */
    public function summary(Tenant $tenant, Client $client): array
    {
        $this->assertClient($tenant, $client);

        return $this->summariesForClients($tenant, [(int) $client->id])[(int) $client->id];
    }

    /**
     * Resumo em lote para evitar N+1 no portfolio PGDAS-D.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesForClients(Tenant $tenant, array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        if ($clientIds === []) {
            return [];
        }

        $preferences = ClientCommunicationPreference::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('module_key', $this->moduleKey)
            ->where('submodule_key', $this->submoduleKey)
            ->get()
            ->keyBy('client_id');
        $eligible = $this->eligibleChannelsForClients($tenant, $clientIds);
        $dispatches = ClientCommunicationDispatch::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('module_key', $this->moduleKey)
            ->where('submodule_key', $this->submoduleKey)
            ->get(['id', 'client_id', 'status'])
            ->groupBy('client_id');
        $docsByClient = $this->clientsWithLocalDocumentsMap($tenant, $clientIds);

        $result = [];
        foreach ($clientIds as $clientId) {
            /** @var ClientCommunicationPreference|null $persisted */
            $persisted = $preferences->get($clientId);
            $preference = $persisted ?? new ClientCommunicationPreference([
                'tenant_id' => $tenant->id,
                'client_id' => $clientId,
                'module_key' => $this->moduleKey,
                'submodule_key' => $this->submoduleKey,
                'automatic_requested' => false,
                'email_enabled' => false,
                'whatsapp_enabled' => false,
                'lock_version' => 0,
            ]);
            $eligibleChannels = $eligible[$clientId] ?? [];
            /** @var Collection<int, ClientCommunicationDispatch> $clientDispatches */
            $clientDispatches = $dispatches->get($clientId, collect());
            $status = $this->trackingStatus($persisted, $eligibleChannels, $clientDispatches);
            $hasLocalDocuments = $docsByClient[$clientId] ?? false;
            $canSend = $this->resolveCanSend($preference, $eligibleChannels, $hasLocalDocuments);
            $automaticEffective = $this->resolveAutomaticEffective($preference, $eligibleChannels, $hasLocalDocuments);

            $result[$clientId] = $preference->toPublicArray(
                $eligibleChannels,
                $status->value,
                $canSend,
                $automaticEffective,
            );
        }

        return $result;
    }

    /**
     * @param  array{email_enabled: bool, whatsapp_enabled: bool, automatic_requested: bool, lock_version: int}  $input
     */
    public function updatePreferences(
        Tenant $tenant,
        Client $client,
        User $actor,
        array $input,
    ): ClientCommunicationPreference {
        $this->assertClient($tenant, $client);
        $this->assertCanWrite($actor, $client);

        $expectedVersion = (int) $input['lock_version'];
        $automatic = (bool) $input['automatic_requested'];
        $email = (bool) $input['email_enabled'];
        $whatsapp = (bool) $input['whatsapp_enabled'];

        if ($automatic) {
            $this->assertEligibleForAutomatic($tenant, $client, $email, $whatsapp);
        }

        try {
            $preference = DB::transaction(function () use (
                $tenant,
                $client,
                $actor,
                $expectedVersion,
                $automatic,
                $email,
                $whatsapp,
            ): ClientCommunicationPreference {
                $current = ClientCommunicationPreference::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('client_id', $client->id)
                    ->where('module_key', $this->moduleKey)
                    ->where('submodule_key', $this->submoduleKey)
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    if ($expectedVersion !== 0) {
                        throw $this->conflict();
                    }

                    return ClientCommunicationPreference::query()->create([
                        'tenant_id' => $tenant->id,
                        'client_id' => $client->id,
                        'module_key' => $this->moduleKey,
                        'submodule_key' => $this->submoduleKey,
                        'automatic_requested' => $automatic,
                        'email_enabled' => $email,
                        'whatsapp_enabled' => $whatsapp,
                        'lock_version' => 1,
                        'updated_by_user_id' => $actor->id,
                    ]);
                }

                if ((int) $current->lock_version !== $expectedVersion) {
                    throw $this->conflict();
                }

                $affected = DB::table('client_communication_preferences')
                    ->where('id', $current->id)
                    ->where('tenant_id', $tenant->id)
                    ->where('lock_version', $expectedVersion)
                    ->update([
                        'automatic_requested' => $automatic,
                        'email_enabled' => $email,
                        'whatsapp_enabled' => $whatsapp,
                        'lock_version' => $expectedVersion + 1,
                        'updated_by_user_id' => $actor->id,
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw $this->conflict();
                }

                return $current->refresh();
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $this->conflict();
            }

            throw $exception;
        }

        $this->audit->record(
            action: $this->auditPrefix.'.preference.update',
            result: 'SUCCESS',
            subject: $preference,
            context: [
                'client_id' => $client->id,
                'automatic_requested' => $automatic,
                'email_enabled' => $email,
                'whatsapp_enabled' => $whatsapp,
                'lock_version' => $preference->lock_version,
            ],
            userId: $actor->id,
            tenantId: (int) $tenant->id,
        );

        return $preference;
    }

    /**
     * Lote atômico do switch geral. Canais permanecem inalterados.
     *
     * @param  list<int>  $clientIds
     * @return list<ClientCommunicationPreference>
     */
    public function batchSetAutomatic(
        Tenant $tenant,
        User $actor,
        array $clientIds,
        bool $automaticRequested,
    ): array {
        $this->assertCanWrite($actor);

        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        if ($clientIds === [] || count($clientIds) > self::BATCH_LIMIT) {
            throw new HttpException(422, 'Lote deve conter entre 1 e '.self::BATCH_LIMIT.' clientes.');
        }

        $clients = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');
        if ($clients->count() !== count($clientIds)) {
            throw new HttpException(422, 'Lote contém cliente inacessível ao escritório.');
        }

        $eligible = $automaticRequested
            ? $this->eligibleChannelsForClients($tenant, $clientIds)
            : [];

        $updated = DB::transaction(function () use (
            $tenant,
            $actor,
            $clientIds,
            $clients,
            $eligible,
            $automaticRequested,
        ): array {
            $preferences = ClientCommunicationPreference::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('client_id', $clientIds)
                ->where('module_key', $this->moduleKey)
                ->where('submodule_key', $this->submoduleKey)
                ->lockForUpdate()
                ->get()
                ->keyBy('client_id');

            // Valida o lote inteiro antes da primeira escrita.
            if ($automaticRequested) {
                foreach ($clientIds as $clientId) {
                    /** @var ClientCommunicationPreference|null $preference */
                    $preference = $preferences->get($clientId);
                    /** @var Client $client */
                    $client = $clients->get($clientId);
                    if ($preference === null) {
                        throw new HttpException(422, "Cliente {$clientId} não possui canais configurados.");
                    }
                    $this->assertEligibleChannels(
                        $client,
                        (bool) $preference->email_enabled,
                        (bool) $preference->whatsapp_enabled,
                        $eligible[$clientId] ?? [],
                    );
                }
            }

            $result = [];
            foreach ($clientIds as $clientId) {
                /** @var ClientCommunicationPreference|null $preference */
                $preference = $preferences->get($clientId);
                if ($preference === null) {
                    $preference = ClientCommunicationPreference::query()->create([
                        'tenant_id' => $tenant->id,
                        'client_id' => $clientId,
                        'module_key' => $this->moduleKey,
                        'submodule_key' => $this->submoduleKey,
                        'automatic_requested' => false,
                        'email_enabled' => false,
                        'whatsapp_enabled' => false,
                        'lock_version' => 1,
                        'updated_by_user_id' => $actor->id,
                    ]);
                } else {
                    $preference->forceFill([
                        'automatic_requested' => $automaticRequested,
                        'lock_version' => ((int) $preference->lock_version) + 1,
                        'updated_by_user_id' => $actor->id,
                    ])->save();
                }

                $result[] = $preference->refresh();
            }

            return $result;
        });

        $this->audit->record(
            action: $this->auditPrefix.'.preference.bulk_update',
            result: 'SUCCESS',
            subject: $tenant,
            context: [
                'client_ids' => $clientIds,
                'automatic_requested' => $automaticRequested,
                'count' => count($updated),
            ],
            userId: $actor->id,
            tenantId: (int) $tenant->id,
        );

        return $updated;
    }

    /**
     * Prévia mascarada e estritamente local.
     *
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, Client $client): array
    {
        $this->assertClient($tenant, $client);
        $preference = $this->getPreferences($tenant, $client);
        $contacts = $this->eligibleContacts($tenant, $client);
        $eligibleChannels = $this->channelNames($contacts);
        $trackingStatus = $this->trackingStatusForClient($tenant, $client, $preference, $eligibleChannels);
        $timezone = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';
        $periodKey = match ($this->submoduleKey) {
            'pgmei' => (string) CarbonImmutable::now($timezone)->year,
            'dctfweb', 'mit' => DctfwebPeriod::toPeriodKey(
                DctfwebPeriod::expectedPa(null, $timezone)
            ),
            default => PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $timezone)),
        };

        $documents = [];
        if ($this->submoduleKey === self::SUBMODULE && $this->moduleKey === self::MODULE) {
            $documents = PgdasdArtifact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->orderByDesc('observed_at')
                ->limit(20)
                ->get()
                ->map(static fn (PgdasdArtifact $artifact): array => $artifact->toTenantDocumentArray())
                ->values()
                ->all();
        }

        $channels = [
            $this->previewChannel(
                CommunicationChannel::Email,
                (bool) $preference->email_enabled,
                $contacts['email'],
            ),
            $this->previewChannel(
                CommunicationChannel::Whatsapp,
                (bool) $preference->whatsapp_enabled,
                $contacts['whatsapp'],
            ),
        ];

        $warnings = [];
        if (! $preference->email_enabled && ! $preference->whatsapp_enabled) {
            $warnings[] = 'Nenhum canal está habilitado.';
        }
        if ($preference->email_enabled && $contacts['email'] === []) {
            $warnings[] = 'E-mail habilitado sem destinatário elegível.';
        }
        if ($preference->whatsapp_enabled && $contacts['whatsapp'] === []) {
            $warnings[] = 'WhatsApp habilitado sem destinatário elegível.';
        }
        $providerEnabled = (bool) config('fiscal_monitoring.communication.provider_enabled', false);
        if (! $providerEnabled) {
            $warnings[] = 'Envio externo desligado (fail-closed). A fila registra a intenção.';
        }

        $hasLocalDocuments = ! $this->requiresLocalDocuments() || $documents !== [];
        $canSend = $this->resolveCanSend($preference, $eligibleChannels, $hasLocalDocuments);
        $automaticEffective = $this->resolveAutomaticEffective($preference, $eligibleChannels, $hasLocalDocuments);

        return [
            'client' => [
                'id' => $client->id,
                'legal_name' => $client->legal_name,
            ],
            'period_key' => $periodKey,
            'execution_mode' => CommunicationExecutionMode::TemplateOnly->value,
            'can_send' => $canSend,
            'automatic_effective' => $automaticEffective,
            'provider_enabled' => $providerEnabled,
            'preferences' => $preference->toPublicArray(
                $eligibleChannels,
                $trackingStatus->value,
                $canSend,
                $automaticEffective,
            ),
            'channels' => $channels,
            'documents' => $documents,
            'warnings' => $warnings,
        ];
    }

    /**
     * Enfileira dispatch(es) de envio manual. Provider externo só se flag ligada.
     *
     * @return array{queued:int, provider_enabled:bool, dispatches:list<array<string, mixed>>}
     */
    public function requestSend(Tenant $tenant, Client $client, User $actor, ?string $periodKey = null): array
    {
        $this->assertClient($tenant, $client);
        $this->assertCanWrite($actor, $client);

        $preference = $this->getPreferences($tenant, $client);
        if (! $preference->exists) {
            throw new HttpException(422, 'Configure canais de comunicação antes de enviar.');
        }

        if (config('communication.enabled') && config('communication.gateway.enabled')) {
            try {
                $dispatches = app(FiscalCommunicationAutomationService::class)->sendManual(
                    $tenant,
                    $client,
                    $this->moduleKey,
                    $this->submoduleKey,
                    $periodKey ?? $this->defaultPeriodKey($tenant),
                    (int) $actor->id,
                );
            } catch (CommunicationOperationException|CommunicationUnavailableException $error) {
                // Compatibilidade do endpoint PGDAS-D: mantém o status/envelope legado
                // sem capturar toda exception tipada da API.
                throw new HttpException(422, $error->getMessage());
            }

            return [
                'queued' => $dispatches->count(),
                'provider_enabled' => true,
                'dispatches' => $dispatches->map(
                    static fn (ClientCommunicationDispatch $dispatch): array => $dispatch->toPublicArray(),
                )->values()->all(),
            ];
        }

        $contacts = $this->eligibleContacts($tenant, $client);
        $eligibleChannels = $this->channelNames($contacts);
        $this->assertEligibleChannels(
            $client,
            (bool) $preference->email_enabled,
            (bool) $preference->whatsapp_enabled,
            $eligibleChannels,
        );

        $hasLocalDocuments = $this->clientHasLocalDocuments($tenant, (int) $client->id);
        if (! $this->resolveCanSend($preference, $eligibleChannels, $hasLocalDocuments)) {
            throw new HttpException(
                422,
                $this->requiresLocalDocuments() && ! $hasLocalDocuments
                    ? 'Não há documentos locais para enviar.'
                    : 'Nenhum canal elegível para envio.',
            );
        }

        $providerEnabled = (bool) config('fiscal_monitoring.communication.provider_enabled', false);
        $created = $this->queueDispatches(
            $tenant,
            $client,
            $preference,
            $contacts,
            $eligibleChannels,
            (int) $actor->id,
            'manual',
        );

        if ($created === []) {
            throw new HttpException(422, 'Nenhum canal elegível para envio.');
        }

        $this->audit->record(
            action: $this->auditPrefix.'.send.queued',
            result: 'SUCCESS',
            subject: $preference,
            context: [
                'client_id' => $client->id,
                'queued' => count($created),
                'provider_enabled' => $providerEnabled,
            ],
            userId: $actor->id,
            tenantId: (int) $tenant->id,
        );

        return [
            'queued' => count($created),
            'provider_enabled' => $providerEnabled,
            'dispatches' => $created,
        ];
    }

    /**
     * Hook pós-consulta agendada: enfileira se automatic_requested e elegível.
     */
    public function maybeQueueAutomaticAfterConsult(Tenant $tenant, Client $client, ?string $periodKey = null): void
    {
        try {
            if (config('communication.enabled') && config('communication.gateway.enabled')) {
                if ($periodKey !== null && preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
                    app(FiscalCommunicationAutomationService::class)->scheduleAutomatic(
                        $tenant,
                        $client,
                        $this->moduleKey,
                        $this->submoduleKey,
                        $periodKey,
                    );
                }

                return;
            }

            $preference = ClientCommunicationPreference::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('module_key', $this->moduleKey)
                ->where('submodule_key', $this->submoduleKey)
                ->first();
            if ($preference === null || ! $preference->automatic_requested) {
                return;
            }

            $contacts = $this->eligibleContacts($tenant, $client);
            $eligibleChannels = $this->channelNames($contacts);
            $hasLocalDocuments = $this->clientHasLocalDocuments($tenant, (int) $client->id);
            if (! $this->resolveAutomaticEffective($preference, $eligibleChannels, $hasLocalDocuments)) {
                return;
            }

            $actorId = (int) ($preference->updated_by_user_id ?: 0);
            $this->queueDispatches($tenant, $client, $preference, $contacts, $eligibleChannels, $actorId, 'scheduled_consult');
        } catch (\Throwable) {
            // Fail-soft: consulta já concluiu; envio automático não deve derrubar a run.
        }
    }

    private function defaultPeriodKey(Tenant $tenant): string
    {
        $timezone = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';

        return match ($this->submoduleKey) {
            'dctfweb', 'mit' => DctfwebPeriod::toPeriodKey(DctfwebPeriod::expectedPa(null, $timezone)),
            default => PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $timezone)),
        };
    }

    /**
     * @param  array{email: list<ClientContact>, whatsapp: list<ClientContact>}  $contacts
     * @param  list<string>  $eligibleChannels
     * @return list<array<string, mixed>>
     */
    private function queueDispatches(
        Tenant $tenant,
        Client $client,
        ClientCommunicationPreference $preference,
        array $contacts,
        array $eligibleChannels,
        int $actorId,
        string $trigger,
    ): array {
        $timezone = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';
        $periodKey = match ($this->submoduleKey) {
            'pgmei' => (string) CarbonImmutable::now($timezone)->year,
            'dctfweb', 'mit' => DctfwebPeriod::toPeriodKey(
                DctfwebPeriod::expectedPa(null, $timezone)
            ),
            default => PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $timezone)),
        };
        $providerEnabled = (bool) config('fiscal_monitoring.communication.provider_enabled', false);
        $created = [];
        foreach ([CommunicationChannel::Email, CommunicationChannel::Whatsapp] as $channel) {
            $enabled = $channel === CommunicationChannel::Email
                ? (bool) $preference->email_enabled
                : (bool) $preference->whatsapp_enabled;
            if (! $enabled || ! in_array($channel->value, $eligibleChannels, true)) {
                continue;
            }
            $recipients = $channel === CommunicationChannel::Email
                ? $contacts['email']
                : $contacts['whatsapp'];
            /** @var ClientContact|null $first */
            $first = $recipients[0] ?? null;
            if ($first === null) {
                continue;
            }

            $idempotencyKey = $this->buildDispatchIdempotencyKey(
                (int) $client->id,
                $channel,
                $periodKey,
                $trigger,
            );

            if ($trigger === 'scheduled_consult') {
                $alreadyQueued = ClientCommunicationDispatch::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();
                if ($alreadyQueued) {
                    continue;
                }
            }

            try {
                $dispatch = ClientCommunicationDispatch::query()->create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'preference_id' => $preference->id,
                    'module_key' => $this->moduleKey,
                    'submodule_key' => $this->submoduleKey,
                    'period_key' => $periodKey,
                    'channel' => $channel,
                    'status' => CommunicationDispatchStatus::Queued,
                    'recipient_masked' => $this->maskContact($first, $channel),
                    'recipient_hash' => hash('sha256', $channel === CommunicationChannel::Email
                        ? strtolower(trim((string) $first->email))
                        : (preg_replace('/\D/', '', (string) $first->phone) ?? '')),
                    'idempotency_key' => $idempotencyKey,
                    'template_key' => $this->auditPrefix.'.'.$trigger,
                    'template_version' => 1,
                    'provider' => $providerEnabled ? 'configured' : 'disabled',
                    'queued_at' => now(),
                    'metadata' => [
                        'trigger' => $trigger,
                        'provider_enabled' => $providerEnabled,
                        'actor_user_id' => $actorId,
                    ],
                ]);
            } catch (QueryException $e) {
                // Corrida entre runs agendadas: unique (tenant_id, idempotency_key).
                if ($trigger === 'scheduled_consult') {
                    continue;
                }
                throw $e;
            }
            $created[] = $dispatch->toPublicArray();
        }

        return $created;
    }

    /**
     * Chave ≤64 chars. Automático estável por período; manual com nonce curto.
     */
    private function buildDispatchIdempotencyKey(
        int $clientId,
        CommunicationChannel $channel,
        string $periodKey,
        string $trigger,
    ): string {
        $module = match ($this->moduleKey) {
            self::MODULE => 'sm',
            default => substr(preg_replace('/[^a-z0-9]/', '', strtolower($this->moduleKey)) ?: 'mod', 0, 4),
        };
        $submodule = match ($this->submoduleKey) {
            'pgdasd' => 'pd',
            'pgmei' => 'pm',
            'dctfweb' => 'dw',
            'mit' => 'mt',
            'sitfis' => 'sf',
            'fgts' => 'fg',
            default => substr(preg_replace('/[^a-z0-9]/', '', strtolower($this->submoduleKey)) ?: 'sub', 0, 4),
        };
        $channelCode = $channel === CommunicationChannel::Email ? 'e' : 'w';
        $triggerCode = $trigger === 'scheduled_consult' ? 'auto' : 'man';
        $base = sprintf(
            '%s:%s:%d:%s:%s:%s',
            $module,
            $submodule,
            $clientId,
            $channelCode,
            $periodKey,
            $triggerCode,
        );

        if ($trigger === 'scheduled_consult') {
            return strlen($base) <= 64 ? $base : substr(hash('sha256', $base), 0, 64);
        }

        $withNonce = $base.':'.bin2hex(random_bytes(4));

        return strlen($withNonce) <= 64 ? $withNonce : substr(hash('sha256', $withNonce), 0, 64);
    }

    /**
     * @param  list<string>  $eligibleChannels
     */
    private function resolveCanSend(
        ClientCommunicationPreference $preference,
        array $eligibleChannels,
        bool $hasLocalDocuments,
    ): bool {
        if ($this->moduleKey === self::MODULE && $this->submoduleKey === self::SUBMODULE && ! $hasLocalDocuments) {
            return false;
        }

        return
            ((bool) $preference->email_enabled && in_array(CommunicationChannel::Email->value, $eligibleChannels, true))
            || ((bool) $preference->whatsapp_enabled && in_array(CommunicationChannel::Whatsapp->value, $eligibleChannels, true));
    }

    /**
     * @param  list<string>  $eligibleChannels
     */
    private function resolveAutomaticEffective(
        ClientCommunicationPreference $preference,
        array $eligibleChannels,
        bool $hasLocalDocuments,
    ): bool {
        if (! $preference->automatic_requested) {
            return false;
        }

        if (config('communication.enabled') && config('communication.gateway.enabled')) {
            $tenant = Tenant::query()->find($preference->tenant_id);
            $policy = CommunicationAutomationPolicy::query()->withoutGlobalScopes()
                ->with('inbox')
                ->where('tenant_id', $preference->tenant_id)
                ->where('module_key', $preference->module_key)
                ->where('submodule_key', $preference->submodule_key)
                ->where('is_enabled', true)
                ->first();
            if (! $tenant?->communication_enabled
                || ! $preference->whatsapp_enabled
                || $policy?->inbox === null
                || ! $policy->inbox->is_enabled
                || $policy->inbox->revoked_at !== null) {
                return false;
            }

            return app(CommunicationRecipientResolver::class)->resolve(
                $preference,
                $policy->recipient_mode instanceof RecipientMode
                    ? $policy->recipient_mode
                    : RecipientMode::Primary,
            )->isNotEmpty();
        }

        return $this->resolveCanSend($preference, $eligibleChannels, $hasLocalDocuments);
    }

    private function requiresLocalDocuments(): bool
    {
        return $this->moduleKey === self::MODULE && $this->submoduleKey === self::SUBMODULE;
    }

    private function clientHasLocalDocuments(Tenant $tenant, int $clientId): bool
    {
        if (! $this->requiresLocalDocuments()) {
            return true;
        }

        return PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $clientId)
            ->exists();
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, bool>
     */
    private function clientsWithLocalDocumentsMap(Tenant $tenant, array $clientIds): array
    {
        if (! $this->requiresLocalDocuments()) {
            $map = [];
            foreach ($clientIds as $clientId) {
                $map[$clientId] = true;
            }

            return $map;
        }

        $present = PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->distinct()
            ->pluck('client_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $set = array_fill_keys($present, true);
        $map = [];
        foreach ($clientIds as $clientId) {
            $map[$clientId] = isset($set[$clientId]);
        }

        return $map;
    }

    /**
     * Rastreio somente leitura: não cria eventos e não marca READ.
     *
     * @return array<string, mixed>
     */
    public function tracking(Tenant $tenant, Client $client): array
    {
        $this->assertClient($tenant, $client);
        $preference = $this->getPreferences($tenant, $client);
        $eligibleChannels = $this->channelNames($this->eligibleContacts($tenant, $client));
        $dispatches = ClientCommunicationDispatch::query()
            ->withoutGlobalScopes()
            ->with('events')
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('module_key', $this->moduleKey)
            ->where('submodule_key', $this->submoduleKey)
            ->orderByDesc('id')
            ->get();
        $status = $this->trackingStatus(
            $preference->exists ? $preference : null,
            $eligibleChannels,
            $dispatches,
        );

        $channels = [];
        foreach ([CommunicationChannel::Email, CommunicationChannel::Whatsapp] as $channel) {
            $items = $dispatches
                ->filter(fn (ClientCommunicationDispatch $dispatch): bool => $dispatch->channel === $channel)
                ->values();
            $channelStatus = $items->isEmpty()
                ? ($status === CommunicationDispatchStatus::NotConfigured
                    ? CommunicationDispatchStatus::NotConfigured
                    : CommunicationDispatchStatus::NoHistory)
                : $this->aggregateStatus($items);
            $channels[] = [
                'channel' => $channel->value,
                'status' => $channelStatus->value,
                'dispatches' => $items
                    ->map(static fn (ClientCommunicationDispatch $dispatch): array => $dispatch->toPublicArray())
                    ->all(),
            ];
        }

        return [
            'client_id' => $client->id,
            'status' => $status->value,
            'execution_mode' => CommunicationExecutionMode::TemplateOnly->value,
            'channels' => $channels,
        ];
    }

    private function assertEligibleForAutomatic(
        Tenant $tenant,
        Client $client,
        bool $emailEnabled,
        bool $whatsappEnabled,
    ): void {
        $this->assertEligibleChannels(
            $client,
            $emailEnabled,
            $whatsappEnabled,
            $this->channelNames($this->eligibleContacts($tenant, $client)),
        );
    }

    /**
     * @param  list<string>  $eligibleChannels
     */
    private function assertEligibleChannels(
        Client $client,
        bool $emailEnabled,
        bool $whatsappEnabled,
        array $eligibleChannels,
    ): void {
        if (! $emailEnabled && ! $whatsappEnabled) {
            throw new HttpException(422, "Cliente {$client->id}: ativação exige ao menos um canal habilitado.");
        }

        $hasEmail = $emailEnabled && in_array(CommunicationChannel::Email->value, $eligibleChannels, true);
        $hasWhatsapp = $whatsappEnabled && in_array(CommunicationChannel::Whatsapp->value, $eligibleChannels, true);
        if (! $hasEmail && ! $hasWhatsapp) {
            throw new HttpException(422, "Cliente {$client->id}: ativação exige contato ativo elegível.");
        }
    }

    /**
     * @return array{email: list<ClientContact>, whatsapp: list<ClientContact>}
     */
    private function eligibleContacts(Tenant $tenant, Client $client): array
    {
        $contacts = ClientContact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->where('receives_alerts', true)
            ->get();

        $email = [];
        $whatsapp = [];
        foreach ($contacts as $contact) {
            $rawEmail = trim((string) $contact->email);
            if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) !== false) {
                $email[] = $contact;
            }
            $phone = preg_replace('/\D/', '', (string) $contact->phone) ?? '';
            if ($contact->is_whatsapp && strlen($phone) >= 8) {
                $whatsapp[] = $contact;
            }
        }

        return ['email' => $email, 'whatsapp' => $whatsapp];
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, list<string>>
     */
    private function eligibleChannelsForClients(Tenant $tenant, array $clientIds): array
    {
        $contacts = ClientContact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('is_active', true)
            ->where('receives_alerts', true)
            ->get();
        $result = [];
        foreach ($contacts as $contact) {
            $channels = $result[(int) $contact->client_id] ?? [];
            $email = trim((string) $contact->email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $channels[] = CommunicationChannel::Email->value;
            }
            $phone = preg_replace('/\D/', '', (string) $contact->phone) ?? '';
            if ($contact->is_whatsapp && strlen($phone) >= 8) {
                $channels[] = CommunicationChannel::Whatsapp->value;
            }
            $result[(int) $contact->client_id] = array_values(array_unique($channels));
        }

        return $result;
    }

    /**
     * @param  array{email: list<ClientContact>, whatsapp: list<ClientContact>}  $contacts
     * @return list<string>
     */
    private function channelNames(array $contacts): array
    {
        $channels = [];
        if ($contacts['email'] !== []) {
            $channels[] = CommunicationChannel::Email->value;
        }
        if ($contacts['whatsapp'] !== []) {
            $channels[] = CommunicationChannel::Whatsapp->value;
        }

        return $channels;
    }

    /**
     * @param  list<ClientContact>  $contacts
     * @return array<string, mixed>
     */
    private function previewChannel(CommunicationChannel $channel, bool $enabled, array $contacts): array
    {
        return [
            'channel' => $channel->value,
            'enabled' => $enabled,
            'eligible' => $contacts !== [],
            'recipients' => array_map(function (ClientContact $contact) use ($channel): array {
                return [
                    'contact_id' => $contact->id,
                    'name' => $contact->name,
                    'masked' => $this->maskContact($contact, $channel),
                ];
            }, $contacts),
        ];
    }

    private function maskContact(ClientContact $contact, CommunicationChannel $channel): string
    {
        if ($channel === CommunicationChannel::Email) {
            [$local, $domain] = array_pad(explode('@', trim((string) $contact->email), 2), 2, '');
            $first = mb_substr($local, 0, 1);

            return ($first !== '' ? $first : '*').'***@'.$domain;
        }

        $digits = preg_replace('/\D/', '', (string) $contact->phone) ?? '';

        return '***'.substr($digits, -4);
    }

    /**
     * @param  list<string>  $eligibleChannels
     */
    private function trackingStatusForClient(
        Tenant $tenant,
        Client $client,
        ClientCommunicationPreference $preference,
        array $eligibleChannels,
    ): CommunicationDispatchStatus {
        $dispatches = ClientCommunicationDispatch::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('module_key', $this->moduleKey)
            ->where('submodule_key', $this->submoduleKey)
            ->get(['id', 'status']);

        return $this->trackingStatus(
            $preference->exists ? $preference : null,
            $eligibleChannels,
            $dispatches,
        );
    }

    /**
     * @param  list<string>  $eligibleChannels
     * @param  Collection<int, ClientCommunicationDispatch>  $dispatches
     */
    private function trackingStatus(
        ?ClientCommunicationPreference $preference,
        array $eligibleChannels,
        Collection $dispatches,
    ): CommunicationDispatchStatus {
        if ($dispatches->isNotEmpty()) {
            return $this->aggregateStatus($dispatches);
        }

        $configured = $preference !== null
            && (($preference->email_enabled
                && in_array(CommunicationChannel::Email->value, $eligibleChannels, true))
                || ($preference->whatsapp_enabled
                    && in_array(CommunicationChannel::Whatsapp->value, $eligibleChannels, true)));

        return $configured
            ? CommunicationDispatchStatus::NoHistory
            : CommunicationDispatchStatus::NotConfigured;
    }

    /**
     * @param  Collection<int, ClientCommunicationDispatch>  $dispatches
     */
    private function aggregateStatus(Collection $dispatches): CommunicationDispatchStatus
    {
        $statuses = $dispatches
            ->map(static fn (ClientCommunicationDispatch $dispatch): string => $dispatch->status->value)
            ->unique()
            ->values();

        if ($statuses->count() > 1) {
            return CommunicationDispatchStatus::Partial;
        }

        return CommunicationDispatchStatus::tryFrom((string) $statuses->first())
            ?? CommunicationDispatchStatus::NoHistory;
    }

    private function assertCanWrite(User $actor, ?Client $client = null): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::ClientsManage, $client)) {
            throw new HttpException(403, 'Perfil sem permissão para alterar comunicação.');
        }
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }

    private function conflict(): ConflictHttpException
    {
        return new ConflictHttpException('Preferência alterada por outro usuário (lock_version).');
    }
}
