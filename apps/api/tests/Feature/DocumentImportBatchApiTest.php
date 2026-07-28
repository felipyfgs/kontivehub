<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\ImportBatchItemStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\TenantRole;
use App\Jobs\ProcessDocumentImportBatchJob;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class DocumentImportBatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(
            SecureObjectStore::class,
            new DocumentImportBatchMemoryStore,
        );
    }

    public function test_reads_preserve_contract_filters_and_tenant_isolation_under_strict_loading(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()
            ->forTenant($foreignTenant, TenantRole::TenantAdmin)
            ->create();
        $batch = DocumentImportBatch::factory()
            ->forTenant($tenant, $admin)
            ->create([
                'status' => ImportBatchStatus::CompletedWithErrors,
                'file_count' => 2,
                'item_count' => 1,
                'failed_count' => 1,
                'completed_at' => now(),
            ]);
        $foreign = DocumentImportBatch::factory()
            ->forTenant($foreignTenant, $foreignAdmin)
            ->create();
        $item = $this->itemFor($batch, ImportBatchItemStatus::Failed);
        $this->authenticate($admin);

        $list = $this->getJson(
            '/api/v1/documents/import-batches?sort=status&direction=asc&per_page=1',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $batch->public_id)
            ->assertJsonPath('data.0.processed_count', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1);
        $this->assertSame([
            'id',
            'public_id',
            'status',
            'is_terminal',
            'upload_complete',
            'processing_complete',
            'client_id',
            'establishment_id',
            'created_by',
            'file_count',
            'item_count',
            'processed_count',
            'imported_count',
            'duplicate_count',
            'unmatched_count',
            'invalid_count',
            'failed_count',
            'quarantined_count',
            'compressed_bytes',
            'uncompressed_bytes',
            'error_code',
            'error_message',
            'queued_at',
            'processing_started_at',
            'completed_at',
            'created_at',
        ], array_keys($list->json('data.0')));

        $this->getJson('/api/v1/documents/import-batches/'.$batch->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $batch->public_id);
        $this->getJson(
            '/api/v1/documents/import-batches/'.$batch->public_id
            .'/items?status=failed&sort=id&direction=desc',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.batch_id', $batch->public_id)
            ->assertJsonPath('data.0.status', ImportBatchItemStatus::Failed->value);
        $this->getJson('/api/v1/documents/import-batches/'.$foreign->public_id)
            ->assertNotFound();
        $this->getJson('/api/v1/documents/import-batches?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_store_uses_body_or_header_idempotency_without_duplicate_dispatch(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $first = $this->withHeader('Idempotency-Key', 'import-001')->post(
            '/api/v1/documents/import-batches',
            [
                'files' => [
                    UploadedFile::fake()->createWithContent(
                        'document.xml',
                        '<cteProc />',
                    ),
                ],
            ],
        )
            ->assertAccepted()
            ->assertJsonPath('data.status', ImportBatchStatus::Queued->value);

        $second = $this->post('/api/v1/documents/import-batches', [
            'files' => [
                UploadedFile::fake()->createWithContent(
                    'document.xml',
                    '<cteProc />',
                ),
            ],
            'idempotency_key' => 'import-001',
        ])
            ->assertAccepted()
            ->assertJsonPath('data.public_id', $first->json('data.public_id'));

        Queue::assertPushed(ProcessDocumentImportBatchJob::class, 1);
        $this->assertDatabaseCount('document_import_batches', 1);
    }

    public function test_retry_is_authorized_tenant_scoped_and_dispatches_once(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $foreignAdmin = User::factory()
            ->forTenant($foreignTenant, TenantRole::TenantAdmin)
            ->create();
        $batch = DocumentImportBatch::factory()
            ->forTenant($tenant, $admin)
            ->create(['status' => ImportBatchStatus::Failed]);
        $foreignBatch = DocumentImportBatch::factory()
            ->forTenant($foreignTenant, $foreignAdmin)
            ->create(['status' => ImportBatchStatus::Failed]);
        $item = $this->itemFor($batch, ImportBatchItemStatus::Unmatched);
        $foreignItem = $this->itemFor(
            $foreignBatch,
            ImportBatchItemStatus::Unmatched,
        );

        $this->authenticate($viewer);
        $this->postJson(
            '/api/v1/documents/import-batches/'.$batch->public_id
            .'/items/'.$item->id.'/retry',
        )->assertForbidden();

        $this->authenticate($admin);
        $this->postJson(
            '/api/v1/documents/import-batches/'.$batch->public_id
            .'/items/'.$foreignItem->id.'/retry',
        )->assertNotFound();
        $this->postJson(
            '/api/v1/documents/import-batches/'.$batch->public_id
            .'/items/'.$item->id.'/retry',
        )
            ->assertOk()
            ->assertJsonPath('data.batch_id', $batch->public_id)
            ->assertJsonPath('data.status', ImportBatchItemStatus::Pending->value);

        Queue::assertPushed(ProcessDocumentImportBatchJob::class, 1);
    }

    public function test_csv_export_keeps_private_headers_and_tenant_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()
            ->forTenant($foreignTenant, TenantRole::TenantAdmin)
            ->create();
        $batch = DocumentImportBatch::factory()->forTenant($tenant, $admin)->create();
        $foreign = DocumentImportBatch::factory()
            ->forTenant($foreignTenant, $foreignAdmin)
            ->create();
        $this->itemFor($batch, ImportBatchItemStatus::Failed);
        $this->authenticate($admin);

        $response = $this->get(
            '/api/v1/documents/import-batches/'.$batch->public_id.'/export.csv',
        )
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('item_index,source_name,status', $content);
        $this->assertStringContainsString('FAILED', $content);
        $this->assertStringNotContainsString('spool_vault_object_id', $content);

        $this->get(
            '/api/v1/documents/import-batches/'.$foreign->public_id.'/export.csv',
        )->assertNotFound();
    }

    private function itemFor(
        DocumentImportBatch $batch,
        ImportBatchItemStatus $status,
    ): DocumentImportBatchItem {
        return DocumentImportBatchItem::query()->withoutGlobalScopes()->create([
            'tenant_id' => $batch->tenant_id,
            'document_import_batch_id' => $batch->id,
            'item_index' => 0,
            'source_name' => 'document.xml',
            'sha256' => str_repeat('a', 64),
            'status' => $status,
            'result_code' => $status->value,
            'attempts' => 1,
            'spool_vault_object_id' => str_repeat('1', 26),
        ]);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }
}

final class DocumentImportBatchMemoryStore implements SecureObjectStore
{
    /** @var array<string, string> */
    private array $objects = [];

    public function put(string $plaintext, array $metadata = []): string
    {
        $id = str_pad(
            (string) (count($this->objects) + 1),
            26,
            '0',
            STR_PAD_LEFT,
        );
        $this->objects[$id] = $plaintext;

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
        return $this->objects[$objectId]
            ?? throw new RuntimeException('Objeto não encontrado.');
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }

    public function exists(string $objectId): bool
    {
        return isset($this->objects[$objectId]);
    }
}
