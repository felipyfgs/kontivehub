<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\MailboxTriageStatus;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Triagem interna NEW/IN_REVIEW/RESOLVED — NÃO altera leitura oficial remota.
 */
final class MailboxTriageService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function update(
        Office $office,
        MailboxMessage $message,
        MailboxTriageStatus $status,
        User $actor,
        ?string $note = null,
    ): MailboxMessage {
        if ((int) $message->office_id !== (int) $office->id) {
            throw new RuntimeException('Mensagem não pertence ao escritório ativo.');
        }

        $beforeOfficial = $message->official_read_indicator;
        $beforeOfficialAt = $message->official_read_observed_at;

        $message->forceFill([
            'triage_status' => $status,
            'triage_by' => $actor->id,
            'triage_at' => CarbonImmutable::now(),
            'triage_note' => $note !== null ? mb_substr(trim($note), 0, 2000) : $message->triage_note,
        ])->save();

        $fresh = $message->fresh();

        // Invariante: indicador oficial intocado
        if ($fresh->official_read_indicator !== $beforeOfficial
            || (string) $fresh->official_read_observed_at !== (string) $beforeOfficialAt) {
            throw new RuntimeException('Triagem interna não pode alterar leitura oficial.');
        }

        $this->audit->record('mailbox.triage', 'SUCCESS', $fresh, [
            'triage_status' => $status->value,
            'message_id' => $fresh->id,
            'client_id' => $fresh->client_id,
            // sem subject/body
        ], userId: $actor->id, officeId: (int) $office->id);

        return $fresh;
    }

    public function parseStatus(string $value): MailboxTriageStatus
    {
        $status = MailboxTriageStatus::tryFrom(strtoupper(trim($value)));
        if ($status === null) {
            throw new InvalidArgumentException('triage_status inválido (NEW|IN_REVIEW|RESOLVED).');
        }

        return $status;
    }
}
