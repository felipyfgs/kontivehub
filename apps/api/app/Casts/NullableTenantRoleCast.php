<?php

namespace App\Casts;

use App\Enums\TenantRole;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use ValueError;

/**
 * Cast fail-soft: linhas ainda sem backfill (null) não quebram o model.
 *
 * @implements CastsAttributes<TenantRole|null, TenantRole|string|null>
 */
final class NullableTenantRoleCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?TenantRole
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return TenantRole::from((string) $value);
        } catch (ValueError) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof TenantRole) {
            return $value->value;
        }

        return TenantRole::from((string) $value)->value;
    }
}
