<?php

namespace App\Casts;

use App\Enums\PlatformRole;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Lê PLATFORM_ADMIN (legado) e platform_admin (canônico).
 * Coluna `role`: grava storage legado. Coluna `platform_role`: grava canônico.
 *
 * @implements CastsAttributes<PlatformRole|null, PlatformRole|string|null>
 */
final class CompatiblePlatformRoleCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PlatformRole
    {
        if ($value === null || $value === '') {
            return null;
        }

        return PlatformRole::tryFromStorage((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $role = $value instanceof PlatformRole
            ? $value
            : PlatformRole::tryFromStorage((string) $value);

        if ($role === null) {
            throw new \InvalidArgumentException(
                "Papel de plataforma inválido para '{$key}': ".var_export($value, true)
            );
        }

        if ($key === 'platform_role') {
            return $role->canonicalValue();
        }

        return $role->legacyStorageValue();
    }
}
