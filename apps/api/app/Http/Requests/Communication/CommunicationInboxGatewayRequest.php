<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

abstract class CommunicationInboxGatewayRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $inbox = $this->route('inbox');

        return $actor instanceof User
            && $inbox instanceof CommunicationInbox
            && app(CommunicationAccess::class)->canManage($actor, $inbox);
    }

    /** @param array<string, mixed> $parameters */
    final protected function gatewayOperation(array $parameters = []): CommunicationGatewayOperationData
    {
        return new CommunicationGatewayOperationData($parameters);
    }
}
