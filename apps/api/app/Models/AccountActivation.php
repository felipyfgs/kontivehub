<?php

namespace App\Models;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credencial de primeiro acesso (link ou senha provisória).
 * secret_hash nunca é exposto em API; plaintext só em memória na criação/regeneração.
 */
#[Hidden(['email_normalized', 'secret_hash'])]
class AccountActivation extends Model
{
    protected $fillable = [
        'purpose',
        'method',
        'user_id',
        'tenant_id',
        'tenant_membership_id',
        'platform_membership_id',
        'email_normalized',
        'secret_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'generation',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ActivationPurpose::class,
            'method' => ActivationMethod::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'generation' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tenantMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'tenant_membership_id');
    }

    public function platformMembership(): BelongsTo
    {
        return $this->belongsTo(PlatformMembership::class, 'platform_membership_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?? now();

        return $this->expires_at !== null && $this->expires_at->lte($at);
    }

    /**
     * Válida para conclusão: não consumida, não revogada e não expirada.
     */
    public function isValid(?\DateTimeInterface $at = null): bool
    {
        return ! $this->isConsumed()
            && ! $this->isRevoked()
            && ! $this->isExpired($at);
    }

    /**
     * Status sanitizado para API (sem hash/segredo).
     */
    public function publicStatus(?\DateTimeInterface $at = null): string
    {
        if ($this->isConsumed()) {
            return 'consumed';
        }
        if ($this->isRevoked()) {
            return 'revoked';
        }
        if ($this->isExpired($at)) {
            return 'expired';
        }

        return 'pending';
    }

    /**
     * Metadados públicos da ativação (sem secret_hash).
     *
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose->value,
            'method' => $this->method->value,
            'status' => $this->publicStatus(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'consumed_at' => $this->consumed_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'generation' => $this->generation,
            'email_masked' => self::maskEmail($this->email_normalized),
        ];
    }

    public static function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $visible = mb_substr($local, 0, 1);

        return $visible.'***@'.$domain;
    }
}
