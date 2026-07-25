<?php

namespace App\Enums;

/**
 * Máquina de estados por nNF (somente leitura + saga mutante isolada).
 *
 * @see design D4 — build-ma-outbound-nfe-nfce-capture
 */
enum OutboundNumberStatus: string
{
    // Caminho read-only
    case SeedReady = 'SEED_READY';
    case ConsultQueued = 'CONSULT_QUEUED';
    case Consulted = 'CONSULTED';
    case KeyDiscovered = 'KEY_DISCOVERED';
    case XmlPending = 'XML_PENDING';
    case XmlCaptured = 'XML_CAPTURED';
    case Complete = 'COMPLETE';
    case GapPending = 'GAP_PENDING';
    case RetryScheduled = 'RETRY_SCHEDULED';
    case ExhaustedVisible = 'EXHAUSTED_VISIBLE';
    case Blocked = 'BLOCKED';
    case LimitedNoKey = 'LIMITED_NO_KEY'; // 562 sem chNFe / 561 / 613 / 526

    // Saga mutante (desligada por padrão)
    case MutationApproved = 'MUTATION_APPROVED';
    case InutilizationPending = 'INUTILIZATION_PENDING';
    case NumberInutilized = 'NUMBER_INUTILIZED';
    case NumberProvenUsed = 'NUMBER_PROVEN_USED';
    case ProbeSent = 'PROBE_SENT';
    case Rejected539 = 'REJECTED_539';
    case AuthorizedUnexpected = 'AUTHORIZED_UNEXPECTED';
    case CancelPending = 'CANCEL_PENDING';
    case Canceled = 'CANCELED';
    case FiscalIncident = 'FISCAL_INCIDENT';
    case Stopped = 'STOPPED';

    public function isTerminalSuccess(): bool
    {
        return in_array($this, [self::Complete, self::NumberInutilized, self::Stopped, self::Canceled], true);
    }

    public function isGap(): bool
    {
        return in_array($this, [
            self::GapPending,
            self::RetryScheduled,
            self::ExhaustedVisible,
            self::XmlPending,
            self::KeyDiscovered,
            self::LimitedNoKey,
        ], true);
    }

    public function isMutating(): bool
    {
        return in_array($this, [
            self::MutationApproved,
            self::InutilizationPending,
            self::NumberProvenUsed,
            self::ProbeSent,
            self::AuthorizedUnexpected,
            self::CancelPending,
            self::FiscalIncident,
        ], true);
    }
}
