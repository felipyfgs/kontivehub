<?php

namespace App\Services\Integra\Eventos;

use App\Contracts\SecureObjectStore;
use App\Models\SerproEventosRun;
use JsonException;
use RuntimeException;

/** Preserva o resultado one-shot cifrado; o banco recebe apenas referência e digest. */
final class EventosResultArtifactStore
{
    public function __construct(private readonly SecureObjectStore $objects) {}

    /** @return array{object_id:string,sha256:string} */
    public function store(SerproEventosRun $run, mixed $dados): array
    {
        $plaintext = json_encode([
            'version' => 1,
            'dados' => $dados,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'object_id' => $this->objects->put($plaintext, $this->metadata($run)),
            'sha256' => hash('sha256', $plaintext),
        ];
    }

    public function load(SerproEventosRun $run): mixed
    {
        $objectId = trim((string) $run->result_vault_object_id);
        if ($objectId === '' || ! $this->objects->exists($objectId)) {
            throw new RuntimeException('EVENTOS_RESULT_ARTIFACT_MISSING');
        }

        $plaintext = $this->objects->get($objectId, $this->metadata($run));
        if (! hash_equals((string) $run->result_payload_sha256, hash('sha256', $plaintext))) {
            throw new RuntimeException('EVENTOS_RESULT_ARTIFACT_DIGEST_MISMATCH');
        }

        try {
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('EVENTOS_RESULT_ARTIFACT_INVALID', previous: $e);
        }
        if (! is_array($decoded) || ($decoded['version'] ?? null) !== 1 || ! array_key_exists('dados', $decoded)) {
            throw new RuntimeException('EVENTOS_RESULT_ARTIFACT_INVALID');
        }

        return $decoded['dados'];
    }

    /** @return array<string, scalar|null> */
    private function metadata(SerproEventosRun $run): array
    {
        return [
            'purpose' => 'serpro_eventos_result',
            'office_id' => (int) $run->office_id,
            'eventos_run_id' => (int) $run->id,
        ];
    }
}
