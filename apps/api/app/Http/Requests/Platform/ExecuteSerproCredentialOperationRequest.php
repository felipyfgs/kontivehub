<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\AuthenticatedRequest;

final class ExecuteSerproCredentialOperationRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
