<?php

namespace App\Services\Communication\Automation;

use App\DTO\Communication\CommunicationAutomationIndexData;
use App\DTO\Communication\CommunicationAutomationScopeData;
use App\DTO\Communication\CommunicationRecipientConfigurationData;
use App\Enums\CommunicationChannel;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CommunicationAutomationQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationAutomationCatalog $catalog,
    ) {}

    public function index(): CommunicationAutomationIndexData
    {
        return new CommunicationAutomationIndexData(
            policies: CommunicationAutomationPolicy::query()
                ->with('inbox')
                ->orderBy('module_key')
                ->orderBy('submodule_key')
                ->get(),
            inboxes: CommunicationInbox::query()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            supportedScopes: $this->catalog->scopes(),
            tenantEnabled: (bool) $this->currentTenant->tenant()->communication_enabled,
            globalEnabled: (bool) config('communication.enabled'),
        );
    }

    public function recipients(
        Client $client,
        CommunicationAutomationScopeData $scope,
    ): CommunicationRecipientConfigurationData {
        $preference = $this->preference($client, $scope);
        $selectedIdentityIds = $preference?->recipients()
            ->pluck('identity_id')
            ->map(static fn ($id): int => (int) $id)
            ->values() ?? collect();

        return new CommunicationRecipientConfigurationData(
            client: $client,
            preference: $preference,
            selectedIdentityIds: $selectedIdentityIds,
            identities: $this->eligibleIdentities($client),
        );
    }

    public function preference(
        Client $client,
        CommunicationAutomationScopeData $scope,
        bool $lockForUpdate = false,
    ): ?ClientCommunicationPreference {
        $query = ClientCommunicationPreference::query()
            ->where('client_id', $client->id)
            ->where('module_key', $scope->moduleKey)
            ->where('submodule_key', $scope->submoduleKey);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  list<int>|null  $identityIds
     * @return Collection<int, CommunicationIdentity>
     */
    public function eligibleIdentities(
        Client $client,
        ?array $identityIds = null,
        bool $lockForUpdate = false,
    ): Collection {
        $tenantId = $this->currentTenant->id();
        $query = CommunicationIdentity::query()
            ->select('communication_identities.*')
            ->selectRaw('links.is_primary as link_is_primary, links.receives_automatic as link_receives_automatic')
            ->join('communication_identity_links as links', function ($join) use ($client, $tenantId): void {
                $join->on('links.identity_id', '=', 'communication_identities.id')
                    ->where('links.tenant_id', $tenantId)
                    ->where('links.client_id', $client->id);
            })
            ->join('communication_contacts as contacts', function ($join) use ($tenantId): void {
                $join->on('contacts.id', '=', 'communication_identities.contact_id')
                    ->where('contacts.tenant_id', $tenantId);
            })
            ->where('communication_identities.channel', CommunicationChannel::Whatsapp->value)
            ->where('communication_identities.is_active', true)
            ->whereNull('communication_identities.purged_at')
            ->where('contacts.is_active', true)
            ->whereNull('contacts.purged_at')
            ->when(
                $identityIds !== null,
                fn (Builder $identityQuery): Builder => $identityQuery
                    ->whereIn('communication_identities.id', $identityIds),
            )
            ->orderByDesc('links.is_primary')
            ->orderBy('communication_identities.id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }
}
