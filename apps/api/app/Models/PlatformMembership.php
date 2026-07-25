<?php

namespace App\Models;

use App\Casts\CompatiblePlatformRoleCast;
use App\Enums\PlatformRole;
use App\Services\Platform\PlatformOwnerService;
use Database\Factories\PlatformMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Associação global usuário ↔ papel da plataforma.
 * SEM office_id de membership — escopo global estrutural.
 * default_office_id: Office padrão de contexto (não cria OfficeMembership).
 * Durante expand, no máximo uma linha PLATFORM_ADMIN (índice parcial) por instalação.
 */
#[Fillable(['user_id', 'role', 'platform_role', 'is_active', 'default_office_id'])]
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
            'role' => CompatiblePlatformRoleCast::class,
            'platform_role' => CompatiblePlatformRoleCast::class,
            'is_active' => 'boolean',
            'default_office_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'default_office_id');
    }

    public function resolvedPlatformRole(): ?PlatformRole
    {
        if ($this->platform_role instanceof PlatformRole) {
            return $this->platform_role;
        }

        if ($this->role instanceof PlatformRole) {
            return $this->role;
        }

        return null;
    }

    public function isPlatformAdmin(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->resolvedPlatformRole() === PlatformRole::PlatformAdmin;
    }
}
