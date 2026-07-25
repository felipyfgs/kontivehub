<?php

namespace App\Models;

use App\Casts\TermoAuthorizationStateCast;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'environment',
    'status',
    'author_identity_type',
    'author_identity',
    'author_name',
    'certificate_mode',
    'managed_a1_consent',
    'managed_a1_consented_at',
    'author_pfx_vault_object_id',
    'author_fingerprint_sha256',
    'author_cert_valid_from',
    'author_cert_valid_to',
    'termo_vault_object_id',
    'termo_sha256',
    'termo_valid_from',
    'termo_valid_to',
    'termo_destination_cnpj',
    'termo_signed_by',
    'termo_uploaded_at',
    'termo_authorization_state',
    'procurador_token_vault_object_id',
    'procurador_token_expires_at',
    'procurador_etag',
    'last_token_refresh_at',
    'last_validation_result',
    'last_validation_message',
    'last_validated_at',
    'action_required_reason',
    'metadata',
])]
#[Hidden([
    'author_pfx_vault_object_id',
    'termo_vault_object_id',
    'procurador_token_vault_object_id',
])]
class OfficeSerproAuthorization extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => SerproAuthorizationStatus::class,
            'author_identity_type' => AuthorIdentityType::class,
            'certificate_mode' => AuthorCertificateMode::class,
            'managed_a1_consent' => 'boolean',
            'managed_a1_consented_at' => 'immutable_datetime',
            'author_cert_valid_from' => 'immutable_datetime',
            'author_cert_valid_to' => 'immutable_datetime',
            'termo_valid_from' => 'immutable_datetime',
            'termo_valid_to' => 'immutable_datetime',
            'termo_uploaded_at' => 'immutable_datetime',
            'termo_authorization_state' => TermoAuthorizationStateCast::class,
            'procurador_token_expires_at' => 'immutable_datetime',
            'last_token_refresh_at' => 'immutable_datetime',
            'last_validated_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(OfficeSerproAuthorizationEvent::class);
    }

    public function proxyPowers(): HasMany
    {
        return $this->hasMany(TaxProxyPower::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'environment' => $this->environment->value,
            'status' => $this->status->value,
            'author_identity_type' => $this->author_identity_type->value,
            'author_identity_masked' => $this->maskIdentity($this->author_identity),
            'author_name' => $this->author_name,
            'certificate_mode' => $this->certificate_mode->value,
            'managed_a1_consent' => $this->managed_a1_consent,
            'managed_a1_consented_at' => $this->managed_a1_consented_at?->toIso8601String(),
            'has_managed_a1' => $this->author_pfx_vault_object_id !== null,
            'author_fingerprint_sha256' => $this->author_fingerprint_sha256,
            'author_cert_valid_from' => $this->author_cert_valid_from?->toIso8601String(),
            'author_cert_valid_to' => $this->author_cert_valid_to?->toIso8601String(),
            'has_termo' => $this->termo_vault_object_id !== null,
            'termo_sha256' => $this->termo_sha256,
            'termo_valid_from' => $this->termo_valid_from?->toIso8601String(),
            'termo_valid_to' => $this->termo_valid_to?->toIso8601String(),
            'termo_destination_cnpj_masked' => $this->termo_destination_cnpj
                ? $this->maskIdentity($this->termo_destination_cnpj)
                : null,
            'termo_signed_by_masked' => $this->termo_signed_by
                ? $this->maskIdentity($this->termo_signed_by)
                : null,
            'termo_uploaded_at' => $this->termo_uploaded_at?->toIso8601String(),
            'termo_authorization_state' => $this->termo_authorization_state?->value,
            'has_procurador_token' => $this->procurador_token_vault_object_id !== null
                && $this->procurador_token_expires_at !== null
                && $this->procurador_token_expires_at->isFuture(),
            'procurador_token_expires_at' => $this->procurador_token_expires_at?->toIso8601String(),
            'has_procurador_etag' => $this->procurador_etag !== null && $this->procurador_etag !== '',
            'last_token_refresh_at' => $this->last_token_refresh_at?->toIso8601String(),
            'last_validation_result' => $this->last_validation_result,
            'last_validation_message' => $this->last_validation_message,
            'last_validated_at' => $this->last_validated_at?->toIso8601String(),
            'action_required_reason' => $this->action_required_reason,
            'actions_required' => $this->computeActionsRequired(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function computeActionsRequired(): array
    {
        $actions = [];

        if ($this->status === SerproAuthorizationStatus::ActionRequired) {
            $actions[] = [
                'code' => 'SIGNATURE_REQUIRED',
                'message' => $this->action_required_reason
                    ?? 'Nova assinatura do Termo de Autorização é necessária.',
            ];
        }

        if ($this->termo_vault_object_id === null) {
            $actions[] = [
                'code' => 'UPLOAD_TERMO',
                'message' => 'Envie o Termo de Autorização assinado.',
            ];
        } elseif ($this->termo_valid_to !== null && $this->termo_valid_to->isPast()) {
            $actions[] = [
                'code' => 'TERMO_EXPIRED',
                'message' => 'O Termo de Autorização expirou. Envie um novo Termo assinado.',
            ];
        }

        if (
            $this->certificate_mode === AuthorCertificateMode::InteractiveA3
            && $this->status === SerproAuthorizationStatus::ActionRequired
        ) {
            $actions[] = [
                'code' => 'A3_INTERACTIVE',
                'message' => 'Assinatura A3 é interativa e não pode ser automatizada.',
            ];
        }

        if (
            $this->procurador_token_expires_at === null
            || $this->procurador_token_expires_at->isPast()
        ) {
            if ($this->termo_vault_object_id !== null) {
                $actions[] = [
                    'code' => 'REFRESH_PROCURADOR_TOKEN',
                    'message' => 'Token do procurador ausente ou expirado.',
                ];
            }
        }

        return $actions;
    }

    private function maskIdentity(string $value): string
    {
        $value = preg_replace('/\D/', '', $value) ?: $value;
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', max(0, $len - 4)).substr($value, -4);
    }
}
