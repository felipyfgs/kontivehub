<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Support\Facades\Gate;

final class ViewOutboundCapabilitiesRequest extends TenantScopedRequest
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
        return [
            'inbox_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function inbox(): ?CommunicationInbox
    {
        $inboxId = $this->validated('inbox_id');
        if ($inboxId === null) {
            return null;
        }

        $inbox = CommunicationInbox::query()->findOrFail((int) $inboxId);
        Gate::forUser($this->user())->authorize('view', $inbox);

        return $inbox;
    }
}
