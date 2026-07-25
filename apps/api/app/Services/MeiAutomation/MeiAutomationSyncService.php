<?php

namespace App\Services\MeiAutomation;

use App\Enums\MeiAutomationStatus;
use App\Exceptions\MeiAutomationTransportException;
use App\Jobs\MeiAutomation\SyncMeiAutomationAttemptJob;
use App\Models\MeiAutomationAttempt;
use RuntimeException;

final class MeiAutomationSyncService
{
    public function __construct(
        private readonly MeiAutomationClient $client,
        private readonly MeiAutomationAttemptRepository $attempts,
        private readonly MeiAutomationArtifactIngestor $artifacts,
    ) {}

    public function synchronize(MeiAutomationAttempt $attempt): MeiAutomationAttempt
    {
        $this->assertPollingContract();
        $jobId = (string) $attempt->external_job_id;
        if ($jobId === '') {
            throw new RuntimeException('Tentativa MEI ainda não possui job externo.');
        }

        try {
            $job = $this->client->get($jobId);
        } catch (MeiAutomationTransportException $error) {
            if ($error->httpStatus === 404) {
                return $this->attempts->markSyncLost($attempt);
            }

            throw $error;
        }

        $attempt = $this->attempts->synchronize($attempt, $job);
        if ($job->status === MeiAutomationStatus::Succeeded) {
            foreach ($job->artifacts as $descriptor) {
                if (! is_array($descriptor)) {
                    return $this->attempts->markArtifactFailure(
                        $attempt,
                        'ARTIFACT_DESCRIPTOR_INVALID',
                        'Descriptor de artefato MEI inválido.',
                    );
                }
                $attempt = $this->artifacts->ingest($attempt, $descriptor);
                if ($attempt->status !== MeiAutomationStatus::Succeeded) {
                    break;
                }
            }
        }

        return $attempt;
    }

    public function schedule(MeiAutomationAttempt $attempt): void
    {
        $this->assertPollingContract();
        SyncMeiAutomationAttemptJob::dispatch((int) $attempt->office_id, (int) $attempt->id)
            ->delay(now()->addSeconds($this->pollIntervalSeconds()))
            ->onQueue((string) config('mei_automation.queue', 'fiscal'));
    }

    public function pollIntervalSeconds(): int
    {
        return (int) config('mei_automation.poll_interval_seconds', 10);
    }

    public function assertPollingContract(): void
    {
        $poll = $this->pollIntervalSeconds();
        $ttl = (int) config('mei_automation.result_ttl_seconds', 900);
        if ($poll < 1 || $ttl < 60 || $poll >= $ttl) {
            throw new RuntimeException('Poll MEI deve ser positivo e menor que o TTL do job.');
        }
    }
}
