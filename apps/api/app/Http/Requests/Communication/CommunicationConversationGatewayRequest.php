<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

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

        $access = app(Access::class);

        return $this->requiresInboxManagement()
            ? $access->canManage($actor, $inbox)
            : $access->canReply($actor, $inbox);
    }

    protected function requiresInboxManagement(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $parameters */
    final protected function gatewayOperation(array $parameters = []): GatewayOperationData
    {
        return new GatewayOperationData($parameters);
    }
}
