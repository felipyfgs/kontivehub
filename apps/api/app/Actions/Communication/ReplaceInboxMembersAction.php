<?php

namespace App\Actions\Communication;

use App\DTO\Communication\InboxMembersData;
use App\Exceptions\CommunicationInboxApiException;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;

final class ReplaceInboxMembersAction
{
    public function handle(
        CommunicationInbox $inbox,
        InboxMembersData $data,
    ): InboxMembersData {
        DB::transaction(function () use ($inbox, $data): void {
            $locked = CommunicationInbox::query()
                ->whereKey($inbox->id)
                ->lockForUpdate()
                ->firstOrFail();
            $validMembershipIds = TenantMembership::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('is_active', true)
                ->whereIn('id', $data->membershipIds)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($membershipId): int => (int) $membershipId)
                ->all();
            if (count($validMembershipIds) !== count($data->membershipIds)) {
                throw CommunicationInboxApiException::invalidMembership();
            }

            CommunicationInboxMember::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $locked->tenant_id)
                ->where('inbox_id', $locked->id)
                ->delete();

            foreach ($data->membershipIds as $membershipId) {
                CommunicationInboxMember::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'inbox_id' => $locked->id,
                    'tenant_membership_id' => $membershipId,
                    'is_active' => true,
                ]);
            }
        });

        return $data;
    }
}
