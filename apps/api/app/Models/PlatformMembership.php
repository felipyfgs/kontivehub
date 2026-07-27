<?php

namespace App\Models;

use App\Enums\PlatformRole;
use App\Services\Platform\PlatformOwnerService;
use Database\Factories\PlatformMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Associação global usuário ↔ papel da plataforma.
 * SEM tenant_id de membership — escopo global estrutural.
 * default_tenant_id: Tenant padrão de contexto (não cria TenantMembership).
 */
#[Fillable(['user_id', 'role', 'is_active', 'default_tenant_id'])]
class PlatformMembership extends Model
{
    /** @use HasFactory<PlatformMembershipFactory> */
    use HasFactory;

    protected $table = 'platform_memberships';

    protected static function booted(): void
    {
        static::deleting(function (PlatformMembership $membership): void {
            app(PlatformOwnerService::class)->assertMembershipMayBeDeleted($membership);
        });
    }

    protected function casts(): array
    {
        return [
            'role' => PlatformRole::class,
            'is_active' => 'boolean',
            'default_tenant_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'default_tenant_id');
    }

    public function isPlatformAdmin(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->role === PlatformRole::PlatformAdmin;
    }
}
