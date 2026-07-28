<?php

/**
 * Convenções de cache multi-tenant (Lote 8.4).
 *
 * - Toda chave de domínio DEVE incluir tenant_id (ou escopo privilegiado tipado).
 * - Ambiente/locale entram na chave quando alteram o valor.
 * - TTL explícito em segundos; invalidação no write do agregado.
 * - Locks: Cache::lock("tenant:{id}:…", $ttl) com block curto.
 */
return [
    'conventions' => [
        'tenant_prefix' => 'tenant:{tenant_id}:',
        'include_environment' => true,
        'include_locale_when_localized' => true,
        'default_ttl_seconds' => 300,
        'lock_ttl_seconds' => 30,
    ],
    'documented_namespaces' => [
        'tenant:{id}:ops:summary',
        'tenant:{id}:fiscal:insights',
        'mailbox-sync-confirm:{tenant_id}:{hash}',
        'serpro:capability:{tenant_id}:{digest}',
    ],
];
