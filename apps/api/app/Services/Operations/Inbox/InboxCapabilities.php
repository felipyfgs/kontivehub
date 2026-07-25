<?php

namespace App\Services\Operations\Inbox;

/**
 * Capacidades já resolvidas para projeção da inbox.
 *
 * Esta estrutura controla apenas a visibilidade das ações. Os endpoints
 * correspondentes continuam validando TenantAuthorization antes de qualquer
 * efeito operacional.
 */
final readonly class InboxCapabilities
{
    public function __construct(
        public bool $canTriggerSync = false,
        public bool $canManageClients = false,
    ) {}
}
