<?php

namespace App\Models;

use App\Enums\SerproContractStatus;
use App\Enums\SerproEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Contrato SERPRO global (plano de controle). SEM office_id.
 */
#[Fillable([
    'environment',
    'status',
    'active_credential_version_id',
    'credentials_exposed',
    'segregation_class',
    'contractor_cnpj',
    'contractor_name',
    'subject_name',
    'fingerprint_sha256',
    'cert_valid_from',
    'cert_valid_to',
    'activated_at',
    'superseded_at',
    'blocked_at',
    'last_verified_at',
    'last_auth_at',
    'health_status',
    'health_message',
    'pfx_vault_object_id',
    'oauth_vault_object_id',
    'trial_bearer_vault_object_id',
    'token_vault_object_id',
    'token_expires_at',
    'consumer_key_hint',
    'metadata',
    'notes',
])]
#[Hidden([
    'pfx_vault_object_id',
    'oauth_vault_object_id',
    'trial_bearer_vault_object_id',
    'token_vault_object_id',
])]
class SerproContract extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => SerproContractStatus::class,
            'credentials_exposed' => 'boolean',
            'cert_valid_from' => 'immutable_datetime',
            'cert_valid_to' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'blocked_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'last_auth_at' => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    /**
     * Metadados sanitizados para API/CLI — sem vault IDs, sem segredos.
     *
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'environment' => $this->environment->value,
            'status' => $this->status->value,
            'contractor_cnpj_masked' => $this->maskCnpj($this->contractor_cnpj),
            'contractor_name' => $this->contractor_name,
            'subject_name' => $this->subject_name,
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'cert_valid_from' => $this->cert_valid_from?->toIso8601String(),
            'cert_valid_to' => $this->cert_valid_to?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'last_auth_at' => $this->last_auth_at?->toIso8601String(),
            'health_status' => $this->health_status,
            'health_message' => $this->health_message,
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'consumer_key_hint' => $this->consumer_key_hint,
            'credentials_exposed' => (bool) ($this->credentials_exposed ?? false),
            'segregation_class' => $this->segregation_class,
            'active_credential_version_id' => $this->active_credential_version_id,
            'has_pfx' => $this->pfx_vault_object_id !== null,
            'has_oauth' => $this->oauth_vault_object_id !== null,
            'has_trial_bearer' => $this->trial_bearer_vault_object_id !== null,
            'has_cached_token' => $this->token_vault_object_id !== null
                && $this->token_expires_at !== null
                && $this->token_expires_at->isFuture(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Saúde tenant-scoped: sem detalhes comerciais/secretos do contrato global.
     *
     * @return array<string, mixed>
     */
    public function toTenantHealthArray(): array
    {
        $healthy = $this->isUsable()
            && in_array($this->health_status, ['OK', 'HEALTHY', null], true);

        return [
            'environment' => $this->environment->value,
            'available' => $healthy,
            'status' => $healthy ? 'AVAILABLE' : 'UNAVAILABLE',
            'cert_expiring_soon' => $this->cert_valid_to !== null
                && $this->cert_valid_to->lessThan(now()->addDays(30)),
        ];
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
