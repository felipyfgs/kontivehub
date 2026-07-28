<?php

namespace App\Services\Communication\Automation;

use App\Enums\Communication\CommunicationOperationFailure;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDispatchStatus;
use App\Enums\CommunicationExecutionMode;
use App\Exceptions\CommunicationOperationException;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\PgdasdArtifact;
use App\Models\Tenant;
use App\Services\Communication\CommunicationAvailability;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class FiscalCommunicationAutomationService
{
    public function __construct(
        private CommunicationRecipientResolver $recipients,
        private FiscalCommunicationArtifactResolver $artifacts,
        private CommunicationAvailability $availability,
        private CommunicationConversationCanonicalizer $canonicalizer,
        private CommunicationMediaStore $media,
        private CommunicationOutboxService $outbox,
        private CommunicationEventRecorder $events,
    ) {}

    /**
     * Materializa um dispatch por identidade, mas não resolve nem envia o documento antes do cutoff.
     *
     * @return Collection<int, ClientCommunicationDispatch>
     */
    public function scheduleAutomatic(
        Tenant $tenant,
        Client $client,
        string $moduleKey,
        string $submoduleKey,
        string $periodKey,
    ): Collection {
        if (! $this->globallyAvailable($tenant) || ! preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            return collect();
        }

        $policy = CommunicationAutomationPolicy::query()
            ->withoutGlobalScopes()
            ->with(['inbox' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->where('module_key', $moduleKey)
            ->where('submodule_key', $submoduleKey)
            ->where('is_enabled', true)
            ->first();
        $preference = $this->preference($tenant, $client, $moduleKey, $submoduleKey);
        if ($policy === null
            || ! $policy->inbox instanceof CommunicationInbox
            || ! $policy->inbox->is_enabled
            || $policy->inbox->revoked_at !== null
            || $preference === null
            || ! $preference->automatic_requested
            || ! $preference->whatsapp_enabled) {
            return collect();
        }

        $identities = $this->recipients->resolve(
            $preference,
            $policy->recipient_mode instanceof RecipientMode ? $policy->recipient_mode : RecipientMode::Primary,
        );
        if ($identities->isEmpty()) {
            return collect();
        }

        $scheduledAt = $this->cutoff($periodKey, $policy);
        $created = collect();
        foreach ($identities as $identity) {
            $key = $this->automaticKey($tenant, $client, $policy, $identity, $periodKey);
            try {
                $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'idempotency_key' => $key],
                    $this->dispatchAttributes(
                        $tenant,
                        $client,
                        $preference,
                        $policy->inbox,
                        $identity,
                        $moduleKey,
                        $submoduleKey,
                        $periodKey,
                        $key,
                        (string) $policy->template_key,
                        (string) $policy->template_version,
                        $scheduledAt,
                        'automatic',
                        (int) $policy->id,
                    ),
                );
            } catch (QueryException $error) {
                if (! in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw $error;
                }
                $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
            }
            if ($dispatch instanceof ClientCommunicationDispatch && $dispatch->wasRecentlyCreated) {
                $created->push($dispatch);
                $this->events->record(
                    (int) $tenant->id,
                    'FISCAL_COMMUNICATION_SCHEDULED',
                    [
                        'dispatch_id' => (int) $dispatch->id,
                        'client_id' => (int) $client->id,
                        'module_key' => $moduleKey,
                        'submodule_key' => $submoduleKey,
                        'period_key' => $periodKey,
                        'scheduled_at' => $scheduledAt->toIso8601String(),
                    ],
                    inboxId: (int) $policy->inbox->id,
                );
            }
        }

        return $created;
    }

    /**
     * Um envio manual é reenviável: cada chamada recebe chave curta nova e não depende do switch automático.
     *
     * @return Collection<int, ClientCommunicationDispatch>
     */
    public function sendManual(
        Tenant $tenant,
        Client $client,
        string $moduleKey,
        string $submoduleKey,
        string $periodKey,
        ?int $actorUserId = null,
    ): Collection {
        if (! $this->globallyAvailable($tenant) || ! preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            throw new CommunicationOperationException(CommunicationOperationFailure::DisabledOrPeriodInvalid);
        }
        $preference = $this->preference($tenant, $client, $moduleKey, $submoduleKey);
        if ($preference === null || ! $preference->whatsapp_enabled) {
            throw new CommunicationOperationException(CommunicationOperationFailure::WhatsappPreferenceDisabled);
        }
        $policy = CommunicationAutomationPolicy::query()->withoutGlobalScopes()
            ->with(['inbox' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->where('module_key', $moduleKey)
            ->where('submodule_key', $submoduleKey)
            ->first();
        $inbox = $policy?->inbox;
        if (! $inbox instanceof CommunicationInbox) {
            $inbox = CommunicationInbox::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->where('is_default', true)->first();
        }
        if (! $inbox instanceof CommunicationInbox) {
            throw new CommunicationOperationException(CommunicationOperationFailure::DefaultInboxMissing);
        }
        $this->availability->assertEnabled($inbox, true);
        $identities = $this->recipients->resolve($preference, RecipientMode::Primary);
        if ($identities->isEmpty()) {
            throw new CommunicationOperationException(CommunicationOperationFailure::EligibleRecipientMissing);
        }

        $created = collect();
        foreach ($identities as $identity) {
            $key = hash('sha256', 'manual|'.Str::ulid().'|'.$identity->id);
            $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()->create(
                $this->dispatchAttributes(
                    $tenant,
                    $client,
                    $preference,
                    $inbox,
                    $identity,
                    $moduleKey,
                    $submoduleKey,
                    $periodKey,
                    $key,
                    (string) ($policy?->template_key ?: 'fiscal-document'),
                    (string) ($policy?->template_version ?: '1'),
                    CarbonImmutable::now(),
                    'manual',
                    $policy?->id !== null ? (int) $policy->id : null,
                    $actorUserId,
                ),
            );
            $this->process((int) $dispatch->id);
            $created->push($dispatch->refresh());
        }

        return $created;
    }

    public function process(int $dispatchId): ?ClientCommunicationDispatch
    {
        $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()
            ->with([
                'client' => fn ($query) => $query->withoutGlobalScopes(),
                'preference' => fn ($query) => $query->withoutGlobalScopes(),
                'inbox' => fn ($query) => $query->withoutGlobalScopes(),
                'inbox.tenant' => fn ($query) => $query->withoutGlobalScopes(),
                'identity' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->find($dispatchId);
        if (! $dispatch instanceof ClientCommunicationDispatch
            || $dispatch->status !== CommunicationDispatchStatus::Scheduled
            || $dispatch->scheduled_at?->isFuture()) {
            return $dispatch;
        }
        $tenant = $dispatch->inbox?->tenant;
        $client = $dispatch->client;
        $inbox = $dispatch->inbox;
        $identity = $dispatch->identity;
        if (! $tenant instanceof Tenant || ! $client instanceof Client
            || ! $inbox instanceof CommunicationInbox || ! $identity instanceof CommunicationIdentity) {
            return $this->fail($dispatch, 'DISPATCH_SCOPE_INVALID');
        }
        try {
            $this->availability->assertEnabled($inbox, true);
        } catch (Throwable) {
            return $this->fail($dispatch, 'INBOX_UNAVAILABLE_AT_CUTOFF');
        }

        $metadata = is_array($dispatch->metadata) ? $dispatch->metadata : [];
        $automatic = ($metadata['trigger'] ?? null) === 'automatic';
        $resolution = $this->artifacts->resolve(
            $tenant,
            $client,
            (string) $dispatch->module_key,
            (string) $dispatch->submodule_key,
            (string) $dispatch->period_key,
            $automatic ? $dispatch->scheduled_at : null,
        );
        if ($resolution->artifact === null) {
            return $this->skipNoDocument($dispatch, $resolution->reason ?? 'DOCUMENT_NOT_FOUND');
        }

        try {
            $bytes = $this->artifacts->read($resolution->artifact, (int) $tenant->id);
        } catch (Throwable) {
            return $this->fail($dispatch, 'DOCUMENT_READ_FAILED');
        }
        $storageContext = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $inbox->id,
            'dispatch_id' => (int) $dispatch->id,
            'sha256' => $resolution->artifact->digest,
        ];
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($stream)) {
            return $this->fail($dispatch, 'MEDIA_STREAM_FAILED');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $stored = $this->media->putStream($stream, $storageContext);
        } catch (Throwable) {
            fclose($stream);

            return $this->fail($dispatch, 'MEDIA_STORE_FAILED');
        }
        fclose($stream);
        if (! hash_equals($resolution->artifact->digest, $stored['sha256'])) {
            $this->media->delete($stored['object_id']);

            return $this->fail($dispatch, 'MEDIA_DIGEST_MISMATCH');
        }

        try {
            $result = DB::transaction(function () use (
                $dispatch,
                $tenant,
                $client,
                $inbox,
                $identity,
                $resolution,
                $stored,
                $storageContext,
            ): ?ClientCommunicationDispatch {
                $lockedIdentity = CommunicationIdentity::query()
                    ->withoutGlobalScopes()
                    ->whereKey($identity->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $canonicalIdentity = $this->canonicalizer->identity($lockedIdentity);
                $identityIds = $this->canonicalizer->identityIds($canonicalIdentity);
                $conversations = CommunicationConversation::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('inbox_id', $inbox->id)
                    ->whereIn('identity_id', $identityIds)
                    ->where('status', '!=', ConversationStatus::Resolved->value)
                    ->whereNull('merged_into_conversation_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $locked = ClientCommunicationDispatch::query()
                    ->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->find($dispatch->id);
                if (! $locked instanceof ClientCommunicationDispatch
                    || $locked->status !== CommunicationDispatchStatus::Scheduled) {
                    return null;
                }
                if ($conversations->count() > 1) {
                    throw new RuntimeException('COMMUNICATION_CANONICAL_CONVERSATION_CONFLICT');
                }
                $conversation = $conversations->first();
                if (! $conversation instanceof CommunicationConversation) {
                    $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
                        'tenant_id' => $tenant->id,
                        'inbox_id' => $inbox->id,
                        'identity_id' => $canonicalIdentity->id,
                        'status' => ConversationStatus::Pending,
                        'work_department_id' => $inbox->work_department_id,
                        'priority' => 0,
                        'lock_version' => 1,
                    ]);
                } elseif ((int) $conversation->identity_id !== (int) $canonicalIdentity->id) {
                    $conversation->forceFill([
                        'identity_id' => $canonicalIdentity->id,
                        'lock_version' => (int) $conversation->lock_version + 1,
                    ])->save();
                }
                DB::table('communication_conversation_clients')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'client_id' => $client->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $body = $this->renderTemplate($locked, $client);
                $providerId = 'fiscal-'.substr(hash('sha256', (string) $locked->idempotency_key), 0, 40);
                $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'inbox_id' => $inbox->id,
                    'conversation_id' => $conversation->id,
                    'identity_id' => $canonicalIdentity->id,
                    'client_communication_dispatch_id' => $locked->id,
                    'direction' => MessageDirection::Outbound,
                    'kind' => MessageKind::Document,
                    'source' => MessageSource::FiscalAutomation,
                    'status' => MessageStatus::Queued,
                    'body_encrypted' => $body,
                    'provider_message_id' => $providerId,
                    'content_digest' => hash('sha256', $body.'|'.$resolution->artifact->digest),
                    'metadata' => [
                        'module_key' => $locked->module_key,
                        'submodule_key' => $locked->submodule_key,
                        'period_key' => $locked->period_key,
                        'artifact_type' => $resolution->artifact->type,
                        'artifact_id' => $resolution->artifact->id,
                        'artifact_digest' => $resolution->artifact->digest,
                    ],
                    'occurred_at' => now(),
                ]);
                $attachment = CommunicationAttachment::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'message_id' => $message->id,
                    'object_id' => $stored['object_id'],
                    'original_name_encrypted' => $resolution->artifact->filename,
                    'mime_type' => $resolution->artifact->contentType,
                    'size_bytes' => $stored['size_bytes'],
                    'sha256' => $stored['sha256'],
                    'storage_context' => $storageContext,
                    'disposition' => 'attachment',
                ]);
                $conversation->forceFill([
                    'status' => ConversationStatus::Pending,
                    'snoozed_until' => null,
                    'last_message_at' => $message->occurred_at,
                    'lock_version' => (int) $conversation->lock_version + 1,
                ])->save();
                $entry = $this->outbox->enqueue($inbox, GatewayCommandType::SendMessage, [
                    'to' => (string) $canonicalIdentity->address_encrypted,
                    'kind' => MessageKind::Document->value,
                    'media' => [
                        'attachment_id' => (int) $attachment->id,
                        'mime_type' => $attachment->mime_type,
                        'filename' => $resolution->artifact->filename,
                        'size_bytes' => (int) $attachment->size_bytes,
                        'sha256' => $attachment->sha256,
                    ],
                ], $message);
                $locked->forceFill([
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'artifact_type' => $resolution->artifact->type,
                    'artifact_id' => $resolution->artifact->id,
                    'artifact_digest' => $resolution->artifact->digest,
                    'pgdasd_artifact_id' => $resolution->artifact->type === PgdasdArtifact::class
                        ? $resolution->artifact->id
                        : null,
                    'status' => CommunicationDispatchStatus::Queued,
                    'queued_at' => now(),
                    'provider' => 'whatsmeow',
                    'provider_external_id' => $providerId,
                    'metadata' => [...(is_array($locked->metadata) ? $locked->metadata : []), 'command_id' => $entry->command_id],
                ])->save();
                $this->events->record(
                    (int) $tenant->id,
                    'FISCAL_MESSAGE_QUEUED',
                    [
                        'dispatch_id' => (int) $locked->id,
                        'message_id' => (int) $message->id,
                        'module_key' => $locked->module_key,
                        'submodule_key' => $locked->submodule_key,
                        'period_key' => $locked->period_key,
                        'has_attachment' => true,
                    ],
                    inboxId: (int) $inbox->id,
                    conversationId: (int) $conversation->id,
                    messageId: (int) $message->id,
                );

                return $locked;
            });
        } catch (Throwable $error) {
            $this->media->delete($stored['object_id']);
            throw $error;
        }
        if ($result === null) {
            $this->media->delete($stored['object_id']);

            return $dispatch->fresh();
        }

        return $result->refresh();
    }

    public function failUnexpected(int $dispatchId, string $code = 'AUTOMATION_PROCESS_FAILED'): void
    {
        $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()->find($dispatchId);
        if ($dispatch instanceof ClientCommunicationDispatch && $dispatch->status === CommunicationDispatchStatus::Scheduled) {
            $this->fail($dispatch, $code);
        }
    }

    private function preference(
        Tenant $tenant,
        Client $client,
        string $moduleKey,
        string $submoduleKey,
    ): ?ClientCommunicationPreference {
        return ClientCommunicationPreference::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('module_key', $moduleKey)
            ->where('submodule_key', $submoduleKey)
            ->first();
    }

    private function globallyAvailable(Tenant $tenant): bool
    {
        return (bool) config('communication.enabled')
            && (bool) config('communication.gateway.enabled')
            && (bool) $tenant->communication_enabled;
    }

    private function cutoff(string $periodKey, CommunicationAutomationPolicy $policy): CarbonImmutable
    {
        $timezone = trim((string) $policy->timezone) ?: 'America/Sao_Paulo';
        $base = CarbonImmutable::createFromFormat('!Y-m', $periodKey, $timezone);
        if (! $base instanceof CarbonImmutable) {
            throw new RuntimeException('Competência inválida para cutoff.');
        }
        $month = $base->startOfMonth()->addMonth();
        $day = min(max(1, (int) $policy->send_day), $month->daysInMonth);
        [$hour, $minute] = array_map('intval', explode(':', substr((string) $policy->send_time, 0, 5)));

        return $month->day($day)->setTime($hour, $minute)->utc();
    }

    private function automaticKey(
        Tenant $tenant,
        Client $client,
        CommunicationAutomationPolicy $policy,
        CommunicationIdentity $identity,
        string $periodKey,
    ): string {
        return hash('sha256', implode('|', [
            $tenant->id,
            $client->id,
            $policy->module_key,
            $policy->submodule_key,
            $periodKey,
            CommunicationChannel::Whatsapp->value,
            $policy->inbox_id,
            $identity->id,
            $policy->template_version,
        ]));
    }

    /** @return array<string, mixed> */
    private function dispatchAttributes(
        Tenant $tenant,
        Client $client,
        ClientCommunicationPreference $preference,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        string $moduleKey,
        string $submoduleKey,
        string $periodKey,
        string $key,
        string $templateKey,
        string $templateVersion,
        CarbonImmutable $scheduledAt,
        string $trigger,
        ?int $policyId,
        ?int $actorUserId = null,
    ): array {
        return [
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'preference_id' => $preference->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'module_key' => $moduleKey,
            'submodule_key' => $submoduleKey,
            'period_key' => $periodKey,
            'channel' => CommunicationChannel::Whatsapp,
            'execution_mode' => CommunicationExecutionMode::WhatsappNative,
            'status' => CommunicationDispatchStatus::Scheduled,
            'recipient_masked' => (string) $identity->address_masked,
            'recipient_hash' => (string) $identity->address_hash,
            'idempotency_key' => $key,
            'template_key' => $templateKey,
            'template_version' => $templateVersion,
            'provider' => 'whatsmeow',
            'scheduled_at' => $scheduledAt,
            'metadata' => array_filter([
                'trigger' => $trigger,
                'automation_policy_id' => $policyId,
                'actor_user_id' => $actorUserId,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    private function renderTemplate(ClientCommunicationDispatch $dispatch, Client $client): string
    {
        $name = trim((string) ($client->display_name ?: $client->legal_name));
        $module = match ((string) $dispatch->submodule_key) {
            'pgdasd' => 'PGDAS-D',
            'pgmei' => 'PGMEI',
            'dctfweb' => 'DCTFWeb',
            default => strtoupper((string) $dispatch->submodule_key),
        };
        $body = sprintf(
            'Olá%s. Segue o documento %s referente à competência %s.',
            $name !== '' ? ', '.$name : '',
            $module,
            $dispatch->period_key,
        );

        return mb_substr($body, 0, 4096);
    }

    private function skipNoDocument(ClientCommunicationDispatch $dispatch, string $reason): ClientCommunicationDispatch
    {
        $changed = ClientCommunicationDispatch::query()->withoutGlobalScopes()
            ->whereKey($dispatch->id)
            ->where('status', CommunicationDispatchStatus::Scheduled->value)
            ->update([
                'status' => CommunicationDispatchStatus::SkippedNoDocument->value,
                'skipped_at' => now(),
                'error_code' => mb_substr($reason, 0, 80),
                'error_message' => null,
                'updated_at' => now(),
            ]);
        if ($changed === 1) {
            $this->events->record(
                (int) $dispatch->tenant_id,
                'FISCAL_COMMUNICATION_SKIPPED',
                [
                    'dispatch_id' => (int) $dispatch->id,
                    'reason' => mb_substr($reason, 0, 80),
                    'period_key' => $dispatch->period_key,
                ],
                inboxId: $dispatch->inbox_id !== null ? (int) $dispatch->inbox_id : null,
            );
        }

        return $dispatch->fresh();
    }

    private function fail(ClientCommunicationDispatch $dispatch, string $code): ClientCommunicationDispatch
    {
        $changed = ClientCommunicationDispatch::query()->withoutGlobalScopes()
            ->whereKey($dispatch->id)
            ->where('status', CommunicationDispatchStatus::Scheduled->value)
            ->update([
                'status' => CommunicationDispatchStatus::Failed->value,
                'failed_at' => now(),
                'error_code' => mb_substr($code, 0, 80),
                'error_message' => null,
                'updated_at' => now(),
            ]);
        if ($changed === 1) {
            $this->events->record(
                (int) $dispatch->tenant_id,
                'FISCAL_COMMUNICATION_FAILED',
                ['dispatch_id' => (int) $dispatch->id, 'reason' => mb_substr($code, 0, 80)],
                inboxId: $dispatch->inbox_id !== null ? (int) $dispatch->inbox_id : null,
            );
        }

        return $dispatch->fresh();
    }
}
