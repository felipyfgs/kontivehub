<?php

namespace App\Models;

use App\Enums\SerproCredentialVersionStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'serpro_contract_id',
    'environment',
    'version_number',
    'status',
    'was_exposed',
    'exposure_reason',
    'exposed_at',
    'consumer_key_hint',
    'fingerprint_sha256',
    'contractor_cnpj',
    'subject_name',
    'cert_valid_from',
    'cert_valid_to',
    'pfx_vault_object_id',
    'oauth_vault_object_id',
    'token_vault_object_id',
    'token_expires_at',
    'verified_at',
    'activated_at',
    'retired_at',
    'compromised_at',
    'verified_by_user_id',
    'activated_by_user_id',
    'segregation_class',
    'metadata',
    'notes',
])]
#[Hidden([
    'pfx_vault_object_id',
    'oauth_vault_object_id',
    'token_vault_object_id',
])]
class SerproCredentialVersion extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => SerproCredentialVersionStatus::class,
            'was_exposed' => 'boolean',
            'exposed_at' => 'immutable_datetime',
            'cert_valid_from' => 'immutable_datetime',
            'cert_valid_to' => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
            'compromised_at' => 'immutable_datetime',
            'segregation_class' => SerproDataSegregationClass::class,
            'metadata' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SerproContract::class, 'serpro_contract_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SerproCredentialApproval::class);
    }

    public function connectionEvidences(): HasMany
    {
        return $this->hasMany(SerproCredentialConnectionEvidence::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function latestValidConnectionEvidence(?\DateTimeInterface $at = null): ?SerproCredentialConnectionEvidence
    {
        $at = $at ?? now();

        if (! Schema::hasTable('serpro_credential_connection_evidences')) {
            return null;
        }

        return $this->connectionEvidences()
            ->where('success', true)
            ->where('invalidated', false)
            ->where('expires_at', '>', $at)
            ->where('fingerprint_sha256', $this->fingerprint_sha256)
            ->orderByDesc('tested_at')
            ->first();
    }

    private function safeLatestEvidence(): ?SerproCredentialConnectionEvidence
    {
        try {
            return $this->latestValidConnectionEvidence();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Versão exposta ainda não retirada do ciclo produtivo.
     */
    public function blocksBillableEgress(): bool
    {
        return $this->was_exposed && ! $this->status->satisfiesRotationGate();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'serpro_contract_id' => $this->serpro_contract_id,
            'environment' => $this->environment->value,
            'version_number' => $this->version_number,
            'status' => $this->status->value,
            'was_exposed' => $this->was_exposed,
            'exposure_reason' => $this->exposure_reason,
            'exposed_at' => $this->exposed_at?->toIso8601String(),
            'consumer_key_hint' => $this->consumer_key_hint,
            'consumer_key_last4' => $this->consumerKeyLast4(),
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'contractor_cnpj_masked' => $this->maskCnpj((string) $this->contractor_cnpj),
            'subject_name' => $this->subject_name,
            'cert_valid_from' => $this->cert_valid_from?->toIso8601String(),
            'cert_valid_to' => $this->cert_valid_to?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
            'compromised_at' => $this->compromised_at?->toIso8601String(),
            'has_pfx' => $this->pfx_vault_object_id !== null,
            'has_oauth' => $this->oauth_vault_object_id !== null,
            'has_cached_token' => $this->token_vault_object_id !== null
                && $this->token_expires_at !== null
                && $this->token_expires_at->isFuture(),
            'has_recent_connection_test' => $this->safeLatestEvidence() !== null,
            'latest_connection_test' => $this->safeLatestEvidence()?->toSanitizedArray(),
            'segregation_class' => $this->segregation_class?->value,
            'blocks_billable_egress' => $this->blocksBillableEgress(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function consumerKeyLast4(): ?string
    {
        $hint = (string) ($this->consumer_key_hint ?? '');
        if ($hint === '') {
            return null;
        }

        // hint armazena ****XXXX (últimos 4) quando comprimento > 4
        if (strlen($hint) >= 4) {
            return substr($hint, -4);
        }

        return null;
    }

    private function maskCnpj(string $cnpj): string
    {
        $cnpj = strtoupper($cnpj);
        if (strlen($cnpj) < 8) {
            return '****';
        }

        return substr($cnpj, 0, 4).str_repeat('*', max(0, strlen($cnpj) - 8)).substr($cnpj, -4);
    }
}
