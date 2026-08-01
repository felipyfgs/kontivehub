<?php

namespace App\Http\Requests\Communication;

use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ViewConversationBulkOperationRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
