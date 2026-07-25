<?php

/**
 * Vault de objetos cifrados (SecureObjectStore).
 *
 * VAULT_MASTER_KEY: base64 de 32 bytes (chave atual).
 * VAULT_MASTER_KEY_VERSION: inteiro da chave atual (selo).
 * VAULT_PREVIOUS_MASTER_KEYS: JSON opcional {"1":"base64...","2":"base64..."} para leitura
 * durante janela de rewrap. Nunca versionar valores reais.
 */
$previous = [];
$rawPrevious = env('VAULT_PREVIOUS_MASTER_KEYS');
if (is_string($rawPrevious) && $rawPrevious !== '') {
    $decoded = json_decode($rawPrevious, true);
    if (is_array($decoded)) {
        $previous = $decoded;
    }
}

return [
    'master_key' => env('VAULT_MASTER_KEY'),
    'master_key_version' => (int) env('VAULT_MASTER_KEY_VERSION', 1),
    /** @var array<int|string, string> version => base64 key */
    'previous_master_keys' => $previous,
    'disk_root' => env('VAULT_DISK_ROOT', storage_path('app/vault')),
];
