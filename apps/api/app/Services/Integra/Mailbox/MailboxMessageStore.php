<?php

namespace App\Services\Integra\Mailbox;

use App\DTO\Mailbox\CaixaPostalDetailResult;
use App\DTO\Mailbox\CaixaPostalListResult;
use App\Enums\MailboxMessagesConsultStatus;
use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Models\Client;
use App\Models\MailboxAttachment;
use App\Models\MailboxContributorState;
use App\Models\MailboxMessage;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistência de lista/detalhe: classificação sensível, hash, retenção; sem duplicar.
 */
final class MailboxMessageStore
{
    public function __construct(
        private readonly MailboxVaultStore $vault,
        private readonly MailboxAlertService $alerts,
    ) {}

    /**
     * Aplica resultado de listagem: upsert de metadados + estado do contribuinte.
     *
     * @return array{created:int,updated:int,messages:list<MailboxMessage>}
     */
    public function applyList(
        Office $office,
        Client $client,
        CaixaPostalListResult $list,
        ?int $runId = null,
    ): array {
        $created = 0;
        $updated = 0;
        $messages = [];
        $retentionDays = (int) config('fiscal_monitoring.mailbox.retention_days', 2555);
        $sensitivity = (string) config('fiscal_monitoring.mailbox.sensitivity_class', 'FISCAL_RESTRICTED');
        $now = CarbonImmutable::now();

        DB::transaction(function () use (
            $office, $client, $list, $runId, $retentionDays, $sensitivity, $now,
            &$created, &$updated, &$messages
        ) {
            foreach ($list->items as $item) {
                $externalId = (string) ($item['external_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $hash = MailboxIdempotency::messageHash((int) $office->id, (int) $client->id, $externalId);
                $existing = MailboxMessage::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->where('message_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                $receivedAt = $this->parseTime($item['received_at'] ?? null);
                $dueAt = $this->parseTime($item['due_at'] ?? null);
                $officialRead = array_key_exists('official_read', $item)
                    ? ($item['official_read'] === null ? null : (bool) $item['official_read'])
                    : null;

                $attrs = [
                    'category_code' => $this->clip($item['category_code'] ?? null, 80),
                    'category_label' => $this->clip($item['category_label'] ?? null, 160),
                    'sender_code' => $this->clip($item['sender_code'] ?? null, 80),
                    'sender_label' => $this->clip($item['sender_label'] ?? null, 160),
                    'subject_preview' => $this->clip($item['subject'] ?? null, 255),
                    'received_at_official' => $receivedAt,
                    'due_at' => $dueAt,
                    'severity_hint' => $this->clip($item['severity_hint'] ?? null, 20),
                    'last_run_id' => $runId,
                    'retention_until' => $now->addDays($retentionDays),
                    'sensitivity_class' => $sensitivity,
                    'source' => MailboxSource::CaixaPostal,
                ];

                if ($officialRead !== null) {
                    $attrs['official_read_indicator'] = $officialRead;
                    $attrs['official_read_observed_at'] = $now;
                }

                if ($existing === null) {
                    $msg = MailboxMessage::query()->create(array_merge($attrs, [
                        'office_id' => $office->id,
                        'client_id' => $client->id,
                        'external_id' => $externalId,
                        'message_hash' => $hash,
                        'triage_status' => MailboxTriageStatus::New,
                        'first_run_id' => $runId,
                        'has_body' => false,
                        'attachment_count' => ! empty($item['has_attachment']) ? 1 : 0,
                    ]));
                    $created++;
                    $this->alerts->ensureForMessage($msg);
                } else {
                    // Não reescreve triagem interna nem corpo já armazenado
                    $existing->forceFill($attrs)->save();
                    $msg = $existing->fresh();
                    $updated++;
                    $this->alerts->ensureForMessage($msg);
                }

                $messages[] = $msg;
            }

            $state = $this->lockState($office, $client);
            $state->forceFill([
                'messages_status' => MailboxMessagesConsultStatus::Consulted,
                'messages_source' => MailboxSource::CaixaPostal,
                'messages_observed_at' => $now,
                'last_list_run_id' => $runId,
                'official_unread_count' => $list->officialUnreadCount,
                'stored_message_count' => MailboxMessage::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->where('client_id', $client->id)
                    ->count(),
            ])->save();
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'messages' => $messages,
        ];
    }

    /**
     * Aplica detalhe: corpo/anexos no cofre; não altera triagem; não força leitura oficial.
     */
    public function applyDetail(
        Office $office,
        Client $client,
        CaixaPostalDetailResult $detail,
        ?int $runId = null,
    ): MailboxMessage {
        $hash = MailboxIdempotency::messageHash((int) $office->id, (int) $client->id, $detail->externalId);
        $retentionDays = (int) config('fiscal_monitoring.mailbox.retention_days', 2555);
        $sensitivity = (string) config('fiscal_monitoring.mailbox.sensitivity_class', 'FISCAL_RESTRICTED');
        $now = CarbonImmutable::now();

        return DB::transaction(function () use (
            $office, $client, $detail, $runId, $hash, $retentionDays, $sensitivity, $now
        ) {
            $msg = MailboxMessage::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('message_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($msg === null) {
                $msg = MailboxMessage::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'external_id' => $detail->externalId,
                    'message_hash' => $hash,
                    'source' => MailboxSource::CaixaPostal,
                    'sensitivity_class' => $sensitivity,
                    'triage_status' => MailboxTriageStatus::New,
                    'first_run_id' => $runId,
                    'retention_until' => $now->addDays($retentionDays),
                ]);
            }

            $fill = [
                'category_code' => $this->clip($detail->categoryCode, 80) ?? $msg->category_code,
                'category_label' => $this->clip($detail->categoryLabel, 160) ?? $msg->category_label,
                'sender_code' => $this->clip($detail->senderCode, 80) ?? $msg->sender_code,
                'sender_label' => $this->clip($detail->senderLabel, 160) ?? $msg->sender_label,
                'subject_preview' => $this->clip($detail->subject, 255) ?? $msg->subject_preview,
                'received_at_official' => $this->parseTime($detail->receivedAt) ?? $msg->received_at_official,
                'due_at' => $this->parseTime($detail->dueAt) ?? $msg->due_at,
                'severity_hint' => $this->clip($detail->severityHint, 20) ?? $msg->severity_hint,
                'last_run_id' => $runId,
                'retention_until' => $now->addDays($retentionDays),
            ];

            // Atualiza indicador oficial somente se a fonte informou (nunca por VIEW interno)
            if ($detail->officialRead !== null) {
                $fill['official_read_indicator'] = $detail->officialRead;
                $fill['official_read_observed_at'] = $now;
            }

            if ($detail->bodyBytes !== null && $detail->bodyBytes !== '') {
                // Idempotente: se mesmo sha, não regrava vault
                $sha = hash('sha256', $detail->bodyBytes);
                if ($msg->body_sha256 !== $sha || $msg->body_vault_object_id === null) {
                    $stored = $this->vault->putBody((int) $office->id, $detail->bodyBytes);
                    $fill['body_vault_object_id'] = $stored['vault_object_id'];
                    $fill['body_sha256'] = $stored['sha256'];
                    $fill['body_byte_size'] = $stored['byte_size'];
                    $fill['body_content_type'] = $detail->bodyContentType;
                    $fill['has_body'] = true;
                }
            }

            $msg->forceFill($fill)->save();

            $attCount = 0;
            foreach ($detail->attachments as $att) {
                $bytes = (string) ($att['bytes'] ?? '');
                if ($bytes === '') {
                    continue;
                }
                $stored = $this->vault->putAttachment((int) $office->id, $bytes);
                $existingAtt = MailboxAttachment::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->where('mailbox_message_id', $msg->id)
                    ->where('content_sha256', $stored['sha256'])
                    ->first();
                if ($existingAtt === null) {
                    MailboxAttachment::query()->create([
                        'office_id' => $office->id,
                        'mailbox_message_id' => $msg->id,
                        'external_id' => $this->clip($att['external_id'] ?? null, 160),
                        'filename_sanitized' => $this->sanitizeFilename($att['filename'] ?? null),
                        'content_type' => $this->clip($att['content_type'] ?? 'application/octet-stream', 80)
                            ?? 'application/octet-stream',
                        'vault_object_id' => $stored['vault_object_id'],
                        'content_sha256' => $stored['sha256'],
                        'byte_size' => $stored['byte_size'],
                        'sensitivity_class' => $sensitivity,
                        'retention_until' => $now->addDays($retentionDays),
                        'created_at' => $now,
                    ]);
                }
                $attCount++;
            }

            if ($attCount > 0) {
                $msg->forceFill([
                    'attachment_count' => MailboxAttachment::query()
                        ->withoutGlobalScopes()
                        ->where('mailbox_message_id', $msg->id)
                        ->count(),
                ])->save();
            }

            $this->alerts->ensureForMessage($msg->fresh());

            return $msg->fresh(['attachments']);
        });
    }

    public function markListError(Office $office, Client $client, ?int $runId = null): void
    {
        $state = $this->lockState($office, $client);
        // Não inventa CONSULTED; preserva UNKNOWN se nunca consultou com sucesso
        if ($state->messages_status === MailboxMessagesConsultStatus::Consulted) {
            // Mantém último sucesso; só anota erro em metadata
            $meta = $state->metadata ?? [];
            $meta['last_list_error_at'] = CarbonImmutable::now()->toIso8601String();
            $state->forceFill(['metadata' => $meta, 'last_list_run_id' => $runId])->save();

            return;
        }

        $state->forceFill([
            'messages_status' => MailboxMessagesConsultStatus::Error,
            'messages_source' => MailboxSource::CaixaPostal,
            'messages_observed_at' => CarbonImmutable::now(),
            'last_list_run_id' => $runId,
        ])->save();
    }

    private function lockState(Office $office, Client $client): MailboxContributorState
    {
        $state = MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->lockForUpdate()
            ->first();

        if ($state !== null) {
            return $state;
        }

        return MailboxContributorState::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function clip(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        return Str::limit($s, $max, '');
    }

    private function sanitizeFilename(mixed $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $base = basename(str_replace(["\0", '/', '\\'], '', (string) $name));
        $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? 'anexo';

        return Str::limit($base, 255, '');
    }
}
