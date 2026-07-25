<?php

namespace App\Models;

use App\Enums\OutboundCaptureMode;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundProfileStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id', 'client_id', 'establishment_id', 'uf', 'environment', 'model',
    'mode', 'status', 'consent_recorded', 'consent_recorded_at', 'mandate_reference',
    'allowlisted', 'allowlisted_at', 'kill_switch', 'kill_switch_reason', 'kill_switch_at',
    'csc_vault_object_id', 'csc_id', 'csc_configured', 'csc_configured_at',
    'activated_by', 'activated_at', 'notes',
])]
#[Hidden(['csc_vault_object_id'])]
class OutboundCaptureProfile extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'model' => OutboundFiscalModel::class,
            'mode' => OutboundCaptureMode::class,
            'status' => OutboundProfileStatus::class,
            'consent_recorded' => 'boolean',
            'consent_recorded_at' => 'immutable_datetime',
            'allowlisted' => 'boolean',
            'allowlisted_at' => 'immutable_datetime',
            'kill_switch' => 'boolean',
            'kill_switch_at' => 'immutable_datetime',
            'csc_configured' => 'boolean',
            'csc_configured_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function seriesCursors(): HasMany
    {
        return $this->hasMany(OutboundSeriesCursor::class);
    }

    public function numberStates(): HasMany
    {
        return $this->hasMany(OutboundNumberState::class);
    }

    public function retrievalRequests(): HasMany
    {
        return $this->hasMany(MaOutboundRetrievalRequest::class);
    }

    public function captureRuns(): HasMany
    {
        return $this->hasMany(OutboundCaptureRun::class);
    }

    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * Metadados públicos de CSC — nunca o valor do token.
     *
     * @return array{configured: bool, csc_id: ?string, configured_at: ?string}
     */
    public function cscPublicState(): array
    {
        return [
            'configured' => (bool) $this->csc_configured,
            'csc_id' => $this->csc_id,
            'configured_at' => $this->csc_configured_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'establishment_id' => $this->establishment_id,
            'uf' => $this->uf,
            'environment' => $this->environment,
            'model' => $this->model->value,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'consent_recorded' => $this->consent_recorded,
            'mandate_reference' => $this->mandate_reference,
            'allowlisted' => $this->allowlisted,
            'kill_switch' => $this->kill_switch,
            'csc' => $this->cscPublicState(),
            'activated_at' => $this->activated_at?->toIso8601String(),
        ];
    }
}
