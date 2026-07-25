<?php

namespace Tests\Unit\MeiAutomation;

use App\Contracts\SecureObjectStore;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use App\Services\MeiAutomation\MeiAutomationArtifactIngestor;
use App\Services\MeiAutomation\MeiAutomationAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MeiAutomationArtifactIngestorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validates_and_ingests_artifact_without_exposing_vault_object_id(): void
    {
        $attempt = $this->attempt();
        $content = "%PDF-1.7\nartifact fixture\n%%EOF";
        $descriptor = $this->descriptor($content);
        Http::fake([
            'http://mei.test/v1/jobs/*/artifacts/*' => Http::response(
                $content,
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
        $objects = Mockery::mock(SecureObjectStore::class);
        $objects->shouldReceive('put')->once()
            ->with($content, Mockery::on(fn (array $metadata): bool => $metadata['purpose'] === 'MEI_PORTAL_ARTIFACT'
                && $metadata['office_id'] === $attempt->office_id))
            ->andReturn('opaque-vault-object');
        $this->app->instance(SecureObjectStore::class, $objects);

        $ingested = app(MeiAutomationArtifactIngestor::class)->ingest($attempt, $descriptor);

        self::assertCount(1, $ingested->vault_artifacts);
        self::assertSame('opaque-vault-object', $ingested->vault_artifacts[0]['object_id']);
        self::assertArrayNotHasKey('object_id', $ingested->toPublicArray()['artifacts'][0]);
        self::assertSame(1, $ingested->safe_metadata['artifact_count']);
    }

    public function test_marks_failure_when_artifact_expired_without_writing_vault(): void
    {
        $attempt = $this->attempt();
        Http::fake(['http://mei.test/v1/jobs/*/artifacts/*' => Http::response([], 410)]);
        $objects = Mockery::mock(SecureObjectStore::class);
        $objects->shouldNotReceive('put');
        $this->app->instance(SecureObjectStore::class, $objects);

        $failed = app(MeiAutomationArtifactIngestor::class)->ingest(
            $attempt,
            $this->descriptor('%PDF-1.7 expired'),
        );

        self::assertSame(MeiAutomationStatus::Failed, $failed->status);
        self::assertSame('ARTIFACT_EXPIRED', $failed->error_code);
        self::assertSame([], $failed->vault_artifacts ?? []);
    }

    public function test_rejects_digest_mismatch_before_writing_vault(): void
    {
        $attempt = $this->attempt();
        $descriptor = $this->descriptor('%PDF-1.7 expected');
        Http::fake([
            'http://mei.test/v1/jobs/*/artifacts/*' => Http::response(
                '%PDF-1.7 changed!',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
        $objects = Mockery::mock(SecureObjectStore::class);
        $objects->shouldNotReceive('put');
        $this->app->instance(SecureObjectStore::class, $objects);

        $failed = app(MeiAutomationArtifactIngestor::class)->ingest($attempt, $descriptor);

        self::assertSame('ARTIFACT_VALIDATION_FAILED', $failed->error_code);
    }

    public function test_rejects_html_disguised_as_pdf_before_writing_vault(): void
    {
        $attempt = $this->attempt();
        $content = '<html>portal error</html>';
        Http::fake([
            'http://mei.test/v1/jobs/*/artifacts/*' => Http::response(
                $content,
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
        $objects = Mockery::mock(SecureObjectStore::class);
        $objects->shouldNotReceive('put');
        $this->app->instance(SecureObjectStore::class, $objects);

        $failed = app(MeiAutomationArtifactIngestor::class)->ingest(
            $attempt,
            $this->descriptor($content),
        );

        self::assertSame('ARTIFACT_VALIDATION_FAILED', $failed->error_code);
    }

    private function attempt(): MeiAutomationAttempt
    {
        config()->set('mei_automation.base_url', 'http://mei.test');
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        config()->set('mei_automation.artifact_max_bytes', 10485760);
        config()->set('mei_automation.artifact_allowed_content_types', ['application/pdf']);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $attempt = app(MeiAutomationAttemptService::class)->start(
            $office,
            $client,
            'pgmei.gerardaspdf',
            MeiProvider::ReceitaPortal,
            'artifact:12345678',
            ['cnpj' => '11222333000181', 'competencies' => ['2026-07']],
        );
        $attempt->forceFill([
            'external_job_id' => '0f82d5ec-d69f-4b2b-a2d6-b2c52e0e1b92',
            'status' => MeiAutomationStatus::Succeeded,
        ])->save();

        return $attempt->refresh();
    }

    /** @return array{id:string,name:string,content_type:string,byte_size:int,sha256:string} */
    private function descriptor(string $content): array
    {
        return [
            'id' => '3dfad6d4-f87c-44da-91eb-1e77cf53dd57',
            'name' => 'das.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }
}
