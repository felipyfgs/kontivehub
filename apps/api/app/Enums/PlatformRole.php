<?php

namespace App\Enums;

/**
 * Papéis da plataforma (globais). Separados de {@see OfficeRole} / {@see TenantRole}.
 *
 * Storage legado da coluna `platform_memberships.role` permanece `PLATFORM_ADMIN`
 * até a fase contract. A coluna aditiva `platform_role` grava o valor canônico
 * lowercase via {@see self::canonicalValue()}.
 *
 * PLATFORM_ADMIN / platform_admin NÃO concede acesso fiscal implícito a tenants.
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D1, D3
 */
enum PlatformRole: string
{
    case PlatformAdmin = 'PLATFORM_ADMIN';

    public const CANONICAL_PLATFORM_ADMIN = 'platform_admin';

    public function canonicalValue(): string
    {
        return match ($this) {
            self::PlatformAdmin => self::CANONICAL_PLATFORM_ADMIN,
        };
    }

    /**
     * Valor gravado na coluna legada `role` (sombra dual-write).
     */
    public function legacyStorageValue(): string
    {
        return $this->value;
    }

    /**
     * Aceita storage legado e canônico sem falhar em linhas mistas.
     */
    public static function tryFromStorage(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            self::PlatformAdmin->value, self::CANONICAL_PLATFORM_ADMIN => self::PlatformAdmin,
            default => null,
        };
    }

    public static function fromStorage(string $value): self
    {
        return self::tryFromStorage($value)
            ?? throw new \ValueError("PlatformRole inválido: {$value}");
    }

    /**
     * Valores de storage que representam este papel (queries dual-read).
     *
     * @return list<string>
     */
    public function storageValues(): array
    {
        return array_values(array_unique([
            $this->legacyStorageValue(),
            $this->canonicalValue(),
        ]));
    }
}
