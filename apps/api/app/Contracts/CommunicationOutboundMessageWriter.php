<?php

namespace App\Contracts;

use App\DTO\Communication\MessageCreationData;
use App\DTO\Communication\MessageCreationResult;
use App\Models\CommunicationConversation;

/**
 * Writer transacional compartilhado de mensagens outbound/humanas.
 *
 * Responsável por staging/cleanup de blob, persistência de mensagem/anexo,
 * evento/outbox e efeitos after-commit. Usado pela reply path e pela iniciação.
 */
interface CommunicationOutboundMessageWriter
{
    public function handle(
        CommunicationConversation $conversation,
        MessageCreationData $data,
    ): MessageCreationResult;
}
