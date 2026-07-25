<?php

namespace App\Services\MeiAutomation;

use App\DTO\MeiAutomation\MeiAutomationJobRequest;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use RuntimeException;

final class MeiAutomationAttemptService
{
    public function __construct(
        private readonly MeiAutomationAttemptRepository $attempts,
        private readonly MeiAutomationInputPolicy $inputPolicy,
    ) {}

    /** @param array<string, mixed> $input */
    public function start(
        Office $office,
        Client $client,
        string $operationKey,
        MeiProvider $provider,
        string $idempotencyKey,
        array $input,
        ?FiscalMonitoringRun $run = null,
        ?FiscalMutationOperation $mutation = null,
        int $attemptNumber = 1,
    ): MeiAutomationAttempt {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório da tentativa MEI.');
        }

        $sanitizedInput = $this->inputPolicy->sanitize($operationKey, $input);

        return $this->attempts->createOrGet((int) $office->id, $idempotencyKey, $attemptNumber, [
            'client_id' => $client->id,
            'fiscal_monitoring_run_id' => $run?->id,
            'fiscal_mutation_operation_id' => $mutation?->id,
            'operation_key' => strtolower(trim($operationKey)),
            'provider' => $provider,
            'status' => MeiAutomationStatus::Queued,
            'request_fingerprint' => $this->fingerprint($operationKey, $sanitizedInput),
        ]);
    }

    /** @param array<string, mixed> $input */
    public function jobRequest(MeiAutomationAttempt $attempt, array $input): MeiAutomationJobRequest
    {
        $secret = (string) config('mei_automation.hmac.secret');
        if ($secret === '') {
            throw new RuntimeException('HMAC da automação MEI não configurado.');
        }

        $sanitizedInput = $this->inputPolicy->sanitize((string) $attempt->operation_key, $input);
        $fingerprint = $this->fingerprint((string) $attempt->operation_key, $sanitizedInput);
        if (! hash_equals((string) $attempt->request_fingerprint, $fingerprint)) {
            throw new RuntimeException('Input MEI diverge do fingerprint persistido.');
        }

        return new MeiAutomationJobRequest(
            operationKey: (string) $attempt->operation_key,
            idempotencyKey: (string) $attempt->idempotency_key.':'.(int) $attempt->attempt_number,
            requestFingerprint: (string) $attempt->request_fingerprint,
            clientRef: hash_hmac('sha256', 'office:'.$attempt->office_id.'|client:'.$attempt->client_id, $secret),
            input: $sanitizedInput,
        );
    }

    /** @param array<string, mixed> $input */
    public function fingerprint(string $operationKey, array $input): string
    {
        $canonical = $this->canonicalize([
            'operation_key' => strtolower(trim($operationKey)),
            'input' => $input,
        ]);

        return hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
