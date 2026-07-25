<?php

namespace App\Models;

use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationDispatchStatus;
use App\Enums\CommunicationExecutionMode;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'module_key',
    'submodule_key',
    'automatic_requested',
    'email_enabled',
    'whatsapp_enabled',
    'recipient_mode',
    'lock_version',
    'updated_by_user_id',
])]
class ClientCommunicationPreference extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'automatic_requested' => 'boolean',
            'email_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'recipient_mode' => RecipientMode::class,
            'lock_version' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(ClientCommunicationDispatch::class, 'preference_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CommunicationPreferenceRecipient::class, 'preference_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(
        array $eligibleChannels = [],
        ?string $trackingStatus = null,
        bool $canSend = false,
        bool $automaticEffective = false,
    ): array {
        $native = (bool) config('communication.enabled') && (bool) config('communication.gateway.enabled');
        $providerEnabled = $native || (bool) config('fiscal_monitoring.communication.provider_enabled', false);

        return [
            'client_id' => $this->client_id,
            'email_enabled' => (bool) $this->email_enabled,
            'whatsapp_enabled' => (bool) $this->whatsapp_enabled,
            'recipient_mode' => ($this->recipient_mode instanceof RecipientMode
                ? $this->recipient_mode
                : RecipientMode::Primary)->value,
            'automatic_requested' => (bool) $this->automatic_requested,
            'automatic_effective' => $automaticEffective,
            'can_send' => $canSend,
            'provider_enabled' => $providerEnabled,
            'execution_mode' => $native
                ? CommunicationExecutionMode::WhatsappNative->value
                : CommunicationExecutionMode::TemplateOnly->value,
            'lock_version' => (int) $this->lock_version,
            'eligible_channels' => array_values($eligibleChannels),
            'tracking_status' => $trackingStatus
                ?? ($this->email_enabled || $this->whatsapp_enabled
                    ? CommunicationDispatchStatus::NoHistory->value
                    : CommunicationDispatchStatus::NotConfigured->value),
        ];
    }
}
