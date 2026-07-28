<?php

namespace Tests\Feature\Fiscal;

use App\Contracts\SecureObjectStore;
use App\Enums\TaxGuideEmissionStatus;
use App\Models\AuditLog;
use App\Models\TaxGuideDownloadToken;
use App\Models\TaxGuideVersion;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use App\Services\Fiscal\Guides\GuideDownloadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class GuideDownloadServiceConcurrencyTest extends TestCase
{
    private ?string $schemaName = null;

    private string $originalSearchPath;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(function_exists('pcntl_fork'), 'O teste exige a extensão pcntl.');
        self::assertTrue(function_exists('posix_kill'), 'O teste exige a extensão posix.');

        $this->originalSearchPath = (string) config('database.connections.pgsql.search_path', 'public');
        $this->schemaName = 'guide_download_'.strtolower(Str::random(12));

        DB::statement('CREATE SCHEMA '.$this->schemaName);
        config()->set('database.connections.pgsql.search_path', $this->schemaName);
        DB::purge();
        $this->createIsolatedSchema();
    }

    protected function tearDown(): void
    {
        if ($this->schemaName !== null) {
            DB::purge();
            config()->set('database.connections.pgsql.search_path', $this->originalSearchPath);
            DB::purge();
            DB::statement('DROP SCHEMA IF EXISTS '.$this->schemaName.' CASCADE');
        }

        parent::tearDown();
    }

    public function test_only_one_concurrent_request_delivers_a_one_time_token(): void
    {
        $bytes = '%PDF-concurrent-guide';
        [$tenantId, $plainToken] = $this->createDownloadToken($bytes);

        [$claimedByParent, $claimedByChild] = $this->socketPair();
        [$releaseByParent, $releaseByChild] = $this->socketPair();
        $this->app->instance(
            SecureObjectStore::class,
            new BlockingGuideObjectStore($bytes, $claimedByChild, $releaseByChild),
        );

        $children = [];
        try {
            for ($worker = 0; $worker < 2; $worker++) {
                [$resultByParent, $resultByChild] = $this->socketPair();
                [$readyByParent, $readyByChild] = $this->socketPair();
                [$startByParent, $startByChild] = $this->socketPair();
                $applicationName = 'guide-download-'.$worker.'-'.strtolower(Str::random(8));
                $pid = pcntl_fork();

                if ($pid === -1) {
                    foreach ([
                        $resultByParent,
                        $resultByChild,
                        $readyByParent,
                        $readyByChild,
                        $startByParent,
                        $startByChild,
                    ] as $stream) {
                        fclose($stream);
                    }

                    self::fail('Não foi possível iniciar o processo concorrente.');
                }

                if ($pid === 0) {
                    fclose($resultByParent);
                    fclose($readyByParent);
                    fclose($startByParent);
                    fclose($claimedByParent);
                    fclose($releaseByParent);
                    DB::purge();
                    DB::statement("SET application_name TO '{$applicationName}'");
                    fwrite($readyByChild, '1');
                    fclose($readyByChild);
                    fread($startByChild, 1);

                    try {
                        $payload = app(GuideDownloadService::class)->consumeToken($plainToken, $tenantId);
                        $result = $payload['bytes'] === $bytes ? 'delivered' : 'invalid-payload';
                    } catch (GuideException) {
                        $result = 'rejected';
                    } catch (\Throwable $exception) {
                        $result = 'error:'.$exception::class.':'.$exception->getMessage();
                    }

                    fwrite($resultByChild, $result);
                    fclose($resultByChild);
                    exit(0);
                }

                fclose($resultByChild);
                fclose($readyByChild);
                fclose($startByChild);
                $children[] = [
                    'pid' => $pid,
                    'application_name' => $applicationName,
                    'result' => $resultByParent,
                    'ready' => $readyByParent,
                    'start' => $startByParent,
                    'reaped' => false,
                ];
            }

            foreach ($children as $child) {
                stream_set_timeout($child['ready'], 5);
                self::assertSame('1', fread($child['ready'], 1));
                fclose($child['ready']);
            }
            foreach ($children as $child) {
                fwrite($child['start'], '1');
                fclose($child['start']);
            }

            stream_set_timeout($claimedByParent, 5);
            $winnerPid = (int) trim((string) fgets($claimedByParent));
            self::assertGreaterThan(0, $winnerPid, 'Nenhuma requisição chegou à leitura protegida.');

            $loser = collect($children)->firstWhere('pid', '!=', $winnerPid);
            self::assertIsArray($loser);
            $this->assertConnectionWaitsForLock($loser['application_name']);

            fwrite($releaseByParent, '1');
            fclose($releaseByParent);

            $results = [];
            foreach ($children as $index => $child) {
                stream_set_timeout($child['result'], 10);
                $results[] = stream_get_contents($child['result']);
                fclose($child['result']);
                $status = $this->reapChild($child['pid']);
                if ($status === null) {
                    self::fail("O processo filho {$child['pid']} não pôde ser finalizado.");
                }
                $children[$index]['reaped'] = true;
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }

            sort($results);
            self::assertSame(['delivered', 'rejected'], $results);
            self::assertNotNull(
                TaxGuideDownloadToken::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('token_hash', hash('sha256', $plainToken))
                    ->sole()
                    ->used_at,
            );
            self::assertSame(
                1,
                AuditLog::query()
                    ->where('tenant_id', $tenantId)
                    ->where('action', 'tax_guide.download.deliver')
                    ->count(),
            );
        } finally {
            foreach ($children as $child) {
                if (is_resource($child['start'])) {
                    @fwrite($child['start'], '1');
                    fclose($child['start']);
                }
            }
            if (is_resource($releaseByParent)) {
                @fwrite($releaseByParent, '1');
                fclose($releaseByParent);
            }

            foreach ($children as $child) {
                foreach (['ready', 'result'] as $stream) {
                    if (is_resource($child[$stream])) {
                        fclose($child[$stream]);
                    }
                }
                if (! $child['reaped']) {
                    $this->reapChild($child['pid']);
                }
            }
            foreach ([$claimedByParent, $claimedByChild, $releaseByChild] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function test_child_cleanup_is_bounded_when_a_worker_does_not_exit(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Não foi possível iniciar o processo para validar o cleanup.');
        }
        if ($pid === 0) {
            sleep(30);
            exit(0);
        }

        $startedAt = microtime(true);
        $status = $this->reapChild($pid, 0.05);

        self::assertNotNull($status);
        self::assertLessThan(2.0, microtime(true) - $startedAt);
        self::assertTrue(pcntl_wifsignaled($status));
    }

    public function test_storage_failure_rolls_back_the_token_claim_without_success_audit(): void
    {
        [$tenantId, $plainToken] = $this->createDownloadToken('%PDF-storage-failure');
        $this->app->instance(SecureObjectStore::class, new FailingGuideObjectStore);

        try {
            app(GuideDownloadService::class)->consumeToken($plainToken, $tenantId);
            self::fail('A leitura do cofre deveria falhar.');
        } catch (RuntimeException $exception) {
            self::assertSame('Falha simulada ao ler o cofre.', $exception->getMessage());
        }

        self::assertNull(
            TaxGuideDownloadToken::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('token_hash', hash('sha256', $plainToken))
                ->sole()
                ->used_at,
        );
        self::assertSame(0, AuditLog::query()->count());
    }

    public function test_same_token_hash_remains_isolated_between_tenants(): void
    {
        $plainToken = Str::random(48);
        [$firstTenantId, , $firstObjectId] = $this->createDownloadToken(
            '%PDF-first-tenant',
            $plainToken,
        );
        [$secondTenantId, , $secondObjectId] = $this->createDownloadToken(
            '%PDF-second-tenant',
            $plainToken,
        );
        $this->app->instance(SecureObjectStore::class, new MappedGuideObjectStore([
            $firstObjectId => '%PDF-first-tenant',
            $secondObjectId => '%PDF-second-tenant',
        ]));

        $first = app(GuideDownloadService::class)->consumeToken($plainToken, $firstTenantId);

        self::assertSame('%PDF-first-tenant', $first['bytes']);
        self::assertNull(
            TaxGuideDownloadToken::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $secondTenantId)
                ->where('token_hash', hash('sha256', $plainToken))
                ->sole()
                ->used_at,
        );

        $second = app(GuideDownloadService::class)->consumeToken($plainToken, $secondTenantId);

        self::assertSame('%PDF-second-tenant', $second['bytes']);
    }

    /** @return array{int, string, string} */
    private function createDownloadToken(string $bytes, ?string $plainToken = null): array
    {
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Tenant '.Str::random(8),
        ]);
        $userId = DB::table('users')->insertGetId([
            'name' => 'Usuário de teste',
        ]);
        $clientId = DB::table('clients')->insertGetId([
            'tenant_id' => $tenantId,
        ]);
        $guideId = DB::table('tax_guides')->insertGetId([
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
        ]);
        $objectId = 'guide-object-'.str_pad((string) $tenantId, 12, '0', STR_PAD_LEFT);
        $version = TaxGuideVersion::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'tax_guide_id' => $guideId,
            'version_number' => 1,
            'is_current' => true,
            'emission_status' => TaxGuideEmissionStatus::Confirmed,
            'content_sha256' => hash('sha256', $bytes),
            'vault_object_id' => $objectId,
            'content_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'idempotency_key' => 'test|guide|'.Str::uuid(),
        ]);
        $plainToken ??= Str::random(48);
        TaxGuideDownloadToken::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'tax_guide_version_id' => $version->id,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMinute(),
            'created_at' => now(),
        ]);

        return [$tenantId, $plainToken, $objectId];
    }

    private function assertConnectionWaitsForLock(string $applicationName): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $waitEventType = DB::table('pg_catalog.pg_stat_activity')
                ->where('application_name', $applicationName)
                ->value('wait_event_type');

            if ($waitEventType === 'Lock') {
                self::assertSame('Lock', $waitEventType);

                return;
            }

            usleep(20_000);
        }

        self::fail("A conexão {$applicationName} não aguardou o lock do token.");
    }

    private function reapChild(int $pid, float $graceSeconds = 1.0): ?int
    {
        $status = $this->pollChildExit($pid, $graceSeconds);
        if ($status !== null) {
            return $status;
        }

        @posix_kill($pid, SIGTERM);
        $status = $this->pollChildExit($pid, 0.25);
        if ($status !== null) {
            return $status;
        }

        @posix_kill($pid, SIGKILL);

        return $this->pollChildExit($pid, 0.75);
    }

    private function pollChildExit(int $pid, float $timeoutSeconds): ?int
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                return $status;
            }
            if ($waited === -1 && pcntl_get_last_error() !== PCNTL_EINTR) {
                return null;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        return null;
    }

    private function createIsolatedSchema(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('tax_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('tax_guide_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_guide_id')->constrained()->cascadeOnDelete();
            $table->integer('version_number');
            $table->boolean('is_current')->default(false);
            $table->string('emission_status', 30);
            $table->string('content_sha256', 64)->nullable();
            $table->string('vault_object_id', 64)->nullable();
            $table->string('content_type', 80)->nullable();
            $table->bigInteger('byte_size')->default(0);
            $table->string('idempotency_key', 160);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'tax_guide_id', 'version_number']);
        });
        Schema::create('tax_guide_download_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_guide_version_id')->constrained('tax_guide_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'token_hash']);
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('chain_seq')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->bigInteger('subject_id')->nullable();
            $table->string('result', 20);
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('entry_hash', 64)->nullable();
            $table->timestampTz('created_at');
        });
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Não foi possível criar o canal local do teste.');
        }

        return $pair;
    }
}

final class BlockingGuideObjectStore implements SecureObjectStore
{
    /**
     * @param  resource  $claimed
     * @param  resource  $release
     */
    public function __construct(
        private readonly string $bytes,
        private $claimed,
        private $release,
    ) {}

    public function put(string $plaintext, array $metadata = []): string
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function get(string $objectId, array $metadata = []): string
    {
        fwrite($this->claimed, (string) getmypid()."\n");
        stream_set_timeout($this->release, 5);
        if (fread($this->release, 1) !== '1') {
            throw new RuntimeException('Timeout aguardando liberação da leitura protegida.');
        }

        return $this->bytes;
    }

    public function delete(string $objectId): void
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function exists(string $objectId): bool
    {
        return true;
    }
}

final class FailingGuideObjectStore implements SecureObjectStore
{
    public function put(string $plaintext, array $metadata = []): string
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function get(string $objectId, array $metadata = []): string
    {
        throw new RuntimeException('Falha simulada ao ler o cofre.');
    }

    public function delete(string $objectId): void
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function exists(string $objectId): bool
    {
        return true;
    }
}

final class MappedGuideObjectStore implements SecureObjectStore
{
    /** @param  array<string, string>  $objects */
    public function __construct(
        private readonly array $objects,
    ) {}

    public function put(string $plaintext, array $metadata = []): string
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function get(string $objectId, array $metadata = []): string
    {
        return $this->objects[$objectId]
            ?? throw new RuntimeException('Objeto de guia inesperado.');
    }

    public function delete(string $objectId): void
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function exists(string $objectId): bool
    {
        return array_key_exists($objectId, $this->objects);
    }
}
