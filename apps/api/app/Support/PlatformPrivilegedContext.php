<?php

namespace App\Support;

/**
 * Constantes do contexto privilegiado PLATFORM_ADMIN (seleção global de tenant).
 *
 * A seleção grava {@see self::SESSION_KEY} separada de membership /
 * `users.selected_tenant_id`. Resolução em CurrentTenant é task 4.x.
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
final class PlatformPrivilegedContext
{
    /**
     * Chave de sessão SPA para o tenant selecionado em modo privilegiado.
     * NÃO reutiliza CurrentTenant::SESSION_KEY nem users.selected_tenant_id.
     */
    public const SESSION_KEY = 'platform_selected_tenant_id';

    /** access_mode produzido por CurrentTenant em modo privilegiado (task 4.x). */
    public const ACCESS_MODE = 'platform_privileged';
}
