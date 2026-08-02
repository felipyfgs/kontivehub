<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;

final class CommunicationConversationListSnapshotApiException extends ApiDomainException
{
    public static function expired(): self
    {
        return new self(
            'CONVERSATION_LIST_SNAPSHOT_EXPIRED',
            'A visão de não lidas expirou. Reaplique “Não lidas” para atualizar a lista.',
            410,
        );
    }

    public static function unavailable(): self
    {
        return new self(
            'CONVERSATION_LIST_SNAPSHOT_UNAVAILABLE',
            'Não foi possível manter a visão de não lidas agora. Tente novamente em instantes.',
            503,
        );
    }

    public static function tooLarge(): self
    {
        return new self(
            'CONVERSATION_LIST_SNAPSHOT_TOO_LARGE',
            'A visão de não lidas excede o limite de 10.000 conversas. Refine os filtros e tente novamente.',
            422,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }

    public function report(): bool
    {
        if ($this->httpStatus() === 503) {
            Log::error('communication.conversation_list_snapshot.unavailable', [
                'error_code' => $this->stableCode(),
            ]);
        }

        return true;
    }
}
