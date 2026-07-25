<?php

namespace App\Services\MeiAutomation;

use App\Contracts\SecureObjectStore;
use App\Exceptions\MeiAutomationTransportException;
use App\Models\MeiAutomationAttempt;
use RuntimeException;
use Throwable;

final class MeiAutomationArtifactIngestor
{
    public function __construct(
        private readonly MeiAutomationClient $client,
        private readonly MeiAutomationAttemptRepository $attempts,
        private readonly SecureObjectStore $objects,
    ) {}

    /** @param array<string, mixed> $descriptor */
    public function ingest(MeiAutomationAttempt $attempt, array $descriptor): MeiAutomationAttempt
    {
        try {
            $artifact = $this->validateDescriptor($descriptor);
        } catch (RuntimeException) {
            return $this->attempts->markArtifactFailure(
                $attempt,
                'ARTIFACT_DESCRIPTOR_INVALID',
                'Descriptor de artefato MEI inválido.',
            );
        }

        $existing = collect($attempt->vault_artifacts ?? [])->first(
            fn (mixed $item): bool => is_array($item) && ($item['id'] ?? null) === $artifact['id'],
        );
        if (is_array($existing)) {
            return $attempt;
        }

        try {
            $response = $this->client->downloadArtifact((string) $attempt->external_job_id, $artifact['id']);
        } catch (MeiAutomationTransportException $error) {
            if (in_array($error->httpStatus, [404, 410], true)) {
                return $this->attempts->markArtifactFailure(
                    $attempt,
                    'ARTIFACT_EXPIRED',
                    'Artefato MEI expirou antes da ingestão no vault.',
                );
            }

            throw $error;
        }

        $content = $response->body();
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($contentType !== $artifact['content_type']
            || strlen($content) !== $artifact['byte_size']
            || ! hash_equals($artifact['sha256'], hash('sha256', $content))
            || ! $this->validContent($contentType, $content)) {
            return $this->attempts->markArtifactFailure(
                $attempt,
                'ARTIFACT_VALIDATION_FAILED',
                'Artefato MEI não corresponde ao descriptor assinado.',
            );
        }

        $metadata = [
            'purpose' => 'MEI_PORTAL_ARTIFACT',
            'office_id' => (int) $attempt->office_id,
            'client_id' => (int) $attempt->client_id,
            'attempt_id' => (int) $attempt->id,
            'artifact_id' => $artifact['id'],
            'content_type' => $artifact['content_type'],
            'sha256' => $artifact['sha256'],
        ];
        $objectId = $this->objects->put($content, $metadata);

        try {
            return $this->attempts->recordVaultArtifact($attempt, [
                ...$artifact,
                'object_id' => $objectId,
            ]);
        } catch (Throwable $error) {
            $this->objects->delete($objectId);
            throw $error;
        }
    }

    private function validContent(string $contentType, string $content): bool
    {
        if ($contentType === 'application/pdf') {
            return str_starts_with($content, '%PDF-')
                && str_contains(substr($content, -1024), '%%EOF');
        }
        if ($contentType === 'text/plain') {
            $normalized = preg_replace('/\D/', '', $content) ?? '';

            return in_array(strlen($normalized), [44, 47, 48], true);
        }

        return true;
    }

    /** @param array<string, mixed> $descriptor
     * @return array{id:string,name:string,content_type:string,byte_size:int,sha256:string}
     */
    private function validateDescriptor(array $descriptor): array
    {
        $id = is_string($descriptor['id'] ?? null) ? strtolower($descriptor['id']) : '';
        $name = is_string($descriptor['name'] ?? null) ? trim($descriptor['name']) : '';
        $contentType = is_string($descriptor['content_type'] ?? null)
            ? strtolower(trim($descriptor['content_type']))
            : '';
        $byteSize = $descriptor['byte_size'] ?? null;
        $sha256 = is_string($descriptor['sha256'] ?? null) ? strtolower($descriptor['sha256']) : '';
        $allowed = (array) config('mei_automation.artifact_allowed_content_types', []);
        $maximum = (int) config('mei_automation.artifact_max_bytes', 10485760);

        if (! preg_match('/^[0-9a-f-]{36}$/', $id)
            || $name === ''
            || mb_strlen($name) > 120
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || ! in_array($contentType, $allowed, true)
            || ! is_int($byteSize)
            || $byteSize < 1
            || $byteSize > $maximum
            || ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException('Descriptor inválido.');
        }

        return [
            'id' => $id,
            'name' => $name,
            'content_type' => $contentType,
            'byte_size' => $byteSize,
            'sha256' => $sha256,
        ];
    }
}
