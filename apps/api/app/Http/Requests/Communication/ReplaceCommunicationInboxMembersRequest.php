<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\InboxMembersData;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class ReplaceCommunicationInboxMembersRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $inbox = $this->route('inbox');

        return $actor instanceof User
            && $inbox instanceof CommunicationInbox
            && app(Access::class)->canManage($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'membership_ids' => ['present', 'array', 'max:500'],
            'membership_ids.*' => [
                'integer',
                'min:1',
                Rule::exists('tenant_memberships', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)),
            ],
        ];
    }

    public function membersData(): InboxMembersData
    {
        $validated = $this->validated();
        $membershipIds = array_values(array_unique(array_map(
            static fn ($membershipId): int => (int) $membershipId,
            $validated['membership_ids'],
        )));

        return new InboxMembersData($membershipIds);
    }
}
