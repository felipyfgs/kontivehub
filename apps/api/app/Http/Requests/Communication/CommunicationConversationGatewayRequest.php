<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

abstract class CommunicationConversationGatewayRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        if (! $actor instanceof User || ! $conversation instanceof CommunicationConversation) {
            return false;
        }

        $inbox = $conversation->inbox()->first();
        if ($inbox === null) {
            return false;
        }

        $access = app(CommunicationAccess::class);

        return $this->requiresInboxManagement()
            ? $access->canManage($actor, $inbox)
            : $access->canReply($actor, $inbox);
    }

    protected function requiresInboxManagement(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $parameters */
    final protected function gatewayOperation(array $parameters = []): CommunicationGatewayOperationData
    {
        return new CommunicationGatewayOperationData($parameters);
    }
}
