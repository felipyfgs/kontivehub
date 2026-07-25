<?php

namespace App\Services\Communication\Automation;

use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationIdentity;
use Illuminate\Support\Collection;

final class CommunicationRecipientResolver
{
    /** @return Collection<int, CommunicationIdentity> */
    public function resolve(ClientCommunicationPreference $preference, ?RecipientMode $fallback = null): Collection
    {
        $mode = $preference->recipient_mode instanceof RecipientMode
            ? $preference->recipient_mode
            : ($fallback ?? RecipientMode::Primary);

        $query = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->select('communication_identities.*')
            ->join('communication_identity_links as links', 'links.identity_id', '=', 'communication_identities.id')
            ->join('communication_contacts as contacts', 'contacts.id', '=', 'communication_identities.contact_id')
            ->where('communication_identities.office_id', $preference->office_id)
            ->where('links.office_id', $preference->office_id)
            ->where('links.client_id', $preference->client_id)
            ->where('links.receives_automatic', true)
            ->where('communication_identities.channel', CommunicationChannel::Whatsapp->value)
            ->where('communication_identities.is_active', true)
            ->whereNull('communication_identities.purged_at')
            ->where('contacts.is_active', true)
            ->whereNull('contacts.purged_at');

        if ($mode === RecipientMode::Primary) {
            $query->where('links.is_primary', true)->limit(1);
        } elseif ($mode === RecipientMode::Selected) {
            $query->whereExists(function ($selected) use ($preference): void {
                $selected->selectRaw('1')
                    ->from('communication_preference_recipients as recipients')
                    ->whereColumn('recipients.identity_id', 'communication_identities.id')
                    ->where('recipients.preference_id', $preference->id)
                    ->where('recipients.office_id', $preference->office_id);
            });
        }

        return $query->orderByDesc('links.is_primary')
            ->orderBy('communication_identities.id')
            ->distinct()
            ->get();
    }
}
