<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

abstract class CommunicationInboxGatewayRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $inbox = $this->route('inbox');

        return $actor instanceof User
            && $inbox instanceof CommunicationInbox
            && app(Access::class)->canManage($actor, $inbox);
    }

    /** @param array<string, mixed> $parameters */
    final protected function gatewayOperation(array $parameters = []): GatewayOperationData
    {
        return new GatewayOperationData($parameters);
    }
}
