<?php

namespace App\Support;

/**
 * Constantes do contexto privilegiado PLATFORM_ADMIN (seleção global de office).
 *
 * A seleção grava {@see self::SESSION_KEY} separada de membership /
 * `users.selected_office_id`. Resolução em CurrentOffice é task 4.x.
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
final class PlatformPrivilegedContext
{
    /**
     * Chave de sessão SPA para o office selecionado em modo privilegiado.
     * NÃO reutiliza CurrentOffice::SESSION_KEY nem users.selected_office_id.
     */
    public const SESSION_KEY = 'platform_selected_office_id';

    /** access_mode produzido por CurrentOffice em modo privilegiado (task 4.x). */
    public const ACCESS_MODE = 'platform_privileged';
}
