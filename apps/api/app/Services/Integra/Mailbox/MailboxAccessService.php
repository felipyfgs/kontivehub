<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\MailboxAccessAction;
use App\Enums\MailboxTriageStatus;
use App\Models\MailboxAccessEvent;
use App\Models\MailboxAttachment;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Visualização/download com trilha; abertura interna NÃO altera official_read_indicator.
 */
final class MailboxAccessService
{
    public function __construct(
        private readonly MailboxVaultStore $vault,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Registra VIEW e retorna metadados de detalhe (sem bytes).
     * Opcionalmente promove NEW → IN_REVIEW sem tocar leitura oficial.
     *
     * @return array{message:MailboxMessage,official_read_unchanged:bool}
     */
    public function view(
        Office $office,
        MailboxMessage $message,
        ?User $actor = null,
        bool $autoTriageInReview = true,
    ): array {
        $this->assertTenant($office, $message);

        $officialBefore = $message->official_read_indicator;

        if ($autoTriageInReview
            && $message->triage_status === MailboxTriageStatus::New
            && $actor !== null
        ) {
            $message->forceFill([
                'triage_status' => MailboxTriageStatus::InReview,
                'triage_by' => $actor->id,
                'triage_at' => CarbonImmutable::now(),
            ])->save();
        }

        $this->record($office, $message, MailboxAccessAction::View, $actor);

        $fresh = $message->fresh(['attachments']);
        $unchanged = $fresh->official_read_indicator === $officialBefore;

        $this->audit->record('mailbox.message.view', 'SUCCESS', $fresh, [
            'message_id' => $fresh->id,
            'client_id' => $fresh->client_id,
            'official_read_unchanged' => $unchanged,
            // sem subject/body
        ], userId: $actor?->id, officeId: (int) $office->id);

        return [
            'message' => $fresh,
            'official_read_unchanged' => $unchanged,
        ];
    }

    /**
     * @return array{bytes:string,content_type:string,filename:string}
     */
    public function downloadBody(Office $office, MailboxMessage $message, ?User $actor = null): array
    {
        $this->assertTenant($office, $message);

        if (! $message->has_body || $message->body_vault_object_id === null || $message->body_sha256 === null) {
            throw new RuntimeException('Corpo da mensagem não disponível.');
        }

        $bytes = $this->vault->getBody(
            (int) $office->id,
            $message->body_vault_object_id,
            $message->body_sha256,
        );

        $this->record($office, $message, MailboxAccessAction::DownloadBody, $actor);

        $this->audit->record('mailbox.message.download_body', 'SUCCESS', $message, [
            'message_id' => $message->id,
            'client_id' => $message->client_id,
            'byte_size' => strlen($bytes),
            'content_sha256' => $message->body_sha256,
            // sem body
        ], userId: $actor?->id, officeId: (int) $office->id);

        $rawType = trim((string) ($message->body_content_type ?? ''));
        $contentType = $rawType !== '' ? $rawType : 'text/plain; charset=UTF-8';
        if (strcasecmp($contentType, 'text/plain') === 0) {
            $contentType = 'text/plain; charset=UTF-8';
        }

        $ext = match (true) {
            str_contains(strtolower($contentType), 'html') => 'html',
            str_starts_with(strtolower($contentType), 'text/') => 'txt',
            default => 'bin',
        };

        return [
            'bytes' => $bytes,
            'content_type' => $contentType,
            'filename' => 'mailbox-message-'.$message->id.'.'.$ext,
        ];
    }

    /**
     * @return array{bytes:string,content_type:string,filename:string}
     */
    public function downloadAttachment(
        Office $office,
        MailboxMessage $message,
        MailboxAttachment $attachment,
        ?User $actor = null,
    ): array {
        $this->assertTenant($office, $message);

        if ((int) $attachment->office_id !== (int) $office->id
            || (int) $attachment->mailbox_message_id !== (int) $message->id) {
            throw new RuntimeException('Anexo não pertence à mensagem/escritório.');
        }

        $bytes = $this->vault->getAttachment(
            (int) $office->id,
            $attachment->vault_object_id,
            $attachment->content_sha256,
        );

        $this->record(
            $office,
            $message,
            MailboxAccessAction::DownloadAttachment,
            $actor,
            $attachment,
        );

        $this->audit->record('mailbox.attachment.download', 'SUCCESS', $attachment, [
            'message_id' => $message->id,
            'attachment_id' => $attachment->id,
            'byte_size' => strlen($bytes),
            'content_sha256' => $attachment->content_sha256,
            // sem bytes/filename sensível além do sanitizado em metadata se necessário
        ], userId: $actor?->id, officeId: (int) $office->id);

        $name = $attachment->filename_sanitized ?: ('attachment-'.$attachment->id.'.bin');

        return [
            'bytes' => $bytes,
            'content_type' => $attachment->content_type,
            'filename' => $name,
        ];
    }

    private function record(
        Office $office,
        MailboxMessage $message,
        MailboxAccessAction $action,
        ?User $actor,
        ?MailboxAttachment $attachment = null,
    ): void {
        MailboxAccessEvent::query()->create([
            'office_id' => $office->id,
            'mailbox_message_id' => $message->id,
            'mailbox_attachment_id' => $attachment?->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'correlation_id' => $this->audit->correlationId(),
            'ip_address' => request()?->ip(),
            'metadata' => [
                'client_id' => $message->client_id,
            ],
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function assertTenant(Office $office, MailboxMessage $message): void
    {
        if ((int) $message->office_id !== (int) $office->id) {
            throw new RuntimeException('Mensagem não pertence ao escritório ativo.');
        }
    }
}
