<?php

namespace App\Actions\Communication;

use App\DTO\Communication\RecipientConfigurationData;
use App\DTO\Communication\RecipientSelectionData;
use App\Exceptions\CommunicationAutomationApiException;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationPreferenceRecipient;
use App\Services\Communication\Automation\AutomationQuery;
use Illuminate\Support\Facades\DB;

final readonly class UpdateAutomationRecipientsAction
{
    public function __construct(
        private AutomationQuery $query,
    ) {}

    public function handle(
        Client $client,
        RecipientSelectionData $data,
    ): RecipientConfigurationData {
        DB::transaction(function () use ($client, $data): void {
            $preference = $this->query->preference($client, $data->scope, lockForUpdate: true);
            if (! $preference instanceof ClientCommunicationPreference) {
                throw CommunicationAutomationApiException::preferenceRequired();
            }
            if ((int) $preference->lock_version !== $data->lockVersion) {
                throw CommunicationAutomationApiException::recipientVersionConflict();
            }

            $eligibleIdentityIds = $this->query
                ->eligibleIdentities($client, $data->identityIds, lockForUpdate: true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if (count($eligibleIdentityIds) !== count($data->identityIds)) {
                throw CommunicationAutomationApiException::ineligibleRecipient();
            }

            $preference->forceFill([
                'recipient_mode' => $data->recipientMode->value,
                'lock_version' => (int) $preference->lock_version + 1,
            ])->save();

            CommunicationPreferenceRecipient::query()
                ->where('preference_id', $preference->id)
                ->delete();

            foreach ($data->identityIds as $identityId) {
                CommunicationPreferenceRecipient::query()->create([
                    'tenant_id' => $preference->tenant_id,
                    'preference_id' => $preference->id,
                    'identity_id' => $identityId,
                ]);
            }
        });

        return $this->query->recipients($client, $data->scope);
    }
}
