<?php

namespace App\Contracts;

interface SecureObjectStore
{
    /**
     * @param  array<string, scalar|null>  $metadata  Authenticated associated data
     * @return string Object identifier (opaque key)
     */
    public function put(string $plaintext, array $metadata = []): string;

    /**
     * @param  array<string, scalar|null>  $metadata  Must match authenticated data used at put
     */
    public function get(string $objectId, array $metadata = []): string;

    public function delete(string $objectId): void;

    public function exists(string $objectId): bool;
}
