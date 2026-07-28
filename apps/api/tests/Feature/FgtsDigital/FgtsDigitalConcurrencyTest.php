<?php

namespace Tests\Feature\FgtsDigital;

use App\Contracts\SecureObjectStore;
use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalGuideType;
use App\Enums\FgtsDigitalOperation;
use App\Enums\FgtsDigitalRunStatus;
use App\Enums\FgtsDigitalSessionStatus;
use App\Enums\FiscalMutationStatus;
use App\Enums\SerproEnvironment;
use App\Models\FgtsDigitalRun;
use App\Models\FgtsDigitalSession;
use App\Models\FiscalMutationOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\FgtsDigital\FgtsDigitalSessionStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class FgtsDigitalConcurrencyTest extends TestCase
{
    private ?string $schemaName = null;

    private string $originalSearchPath;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertTrue(
            function_exists('pcntl_fork'),
            'O teste exige a extensão pcntl.',
        );
        self::assertTrue(
            function_exists('posix_kill'),
            'O teste exige a extensão posix.',
        );

        $this->originalSearchPath = (string) config(
            'database.connections.pgsql.search_path',
            'public',
        );
        $this->schemaName = 'fgts_concurrency_'.strtolower(Str::random(12));

        DB::statement('CREATE SCHEMA '.$this->schemaName);
        config()->set(
            'database.connections.pgsql.search_path',
            $this->schemaName,
        );
        DB::purge();
        $this->createIsolatedSchema();
        config()->set('fgts_digital.mutations_enabled', true);
    }

    protected function tearDown(): void
    {
        if ($this->schemaName !== null) {
            DB::purge();
            config()->set(
                'database.connections.pgsql.search_path',
                $this->originalSearchPath,
            );
            DB::purge();
            DB::statement(
                'DROP SCHEMA IF EXISTS '.$this->schemaName.' CASCADE',
            );
        }

        parent::tearDown();
    }

    public function test_concurrent_emission_authorization_creates_one_run(): void
    {
        [
            $tenant,
            $user,
            $preview,
            $token,
            $privateRequest,
        ] = $this->createPreview();
        [$claimedByParent, $claimedByChild] = $this->socketPair();
        [$releaseByParent, $releaseByChild] = $this->socketPair();
        $this->app->instance(
            SecureObjectStore::class,
            new BlockingFgtsRequestStore(
                $privateRequest,
                $claimedByChild,
                $releaseByChild,
            ),
        );

        $children = [];
        try {
            for ($worker = 0; $worker < 2; $worker++) {
                [$resultByParent, $resultByChild] = $this->socketPair();
                [$readyByParent, $readyByChild] = $this->socketPair();
                [$startByParent, $startByChild] = $this->socketPair();
                $applicationName = 'fgts-emission-'.$worker.'-'
                    .strtolower(Str::random(8));
                $pid = pcntl_fork();

                if ($pid === -1) {
                    self::fail(
                        'Não foi possível iniciar o processo concorrente.',
                    );
                }

                if ($pid === 0) {
                    fclose($resultByParent);
                    fclose($readyByParent);
                    fclose($startByParent);
                    fclose($claimedByParent);
                    fclose($releaseByParent);
                    DB::purge();
                    DB::statement(
                        "SET application_name TO '{$applicationName}'",
                    );
                    fwrite($readyByChild, '1');
                    fclose($readyByChild);
                    fread($startByChild, 1);

                    try {
                        $result = app(
                            FgtsDigitalPortalService::class,
                        )->authorizeEmission(
                            $tenant,
                            $preview,
                            $user,
                            $token,
                            (string) $preview->confirmation_phrase,
                        );
                        $outcome = ($result['reused'] ? 'reused:' : 'created:')
                            .$result['run']->id;
                    } catch (\Throwable $exception) {
                        $outcome = 'error:'.$exception::class;
                    }

                    fwrite($resultByChild, $outcome);
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
            self::assertGreaterThan(0, $winnerPid);
            $loser = collect($children)->firstWhere(
                'pid',
                '!=',
                $winnerPid,
            );
            self::assertIsArray($loser);
            $this->assertConnectionWaitsForLock(
                $loser['application_name'],
            );

            fwrite($releaseByParent, '1');
            fclose($releaseByParent);

            $results = $this->collectChildResults($children);
            sort($results);
            self::assertCount(2, $results);
            self::assertStringStartsWith('created:', $results[0]);
            self::assertStringStartsWith('reused:', $results[1]);
            self::assertSame(
                substr($results[0], strlen('created:')),
                substr($results[1], strlen('reused:')),
            );
            self::assertSame(
                1,
                FgtsDigitalRun::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('operation', FgtsDigitalOperation::EmitGuide->value)
                    ->count(),
            );
            self::assertTrue(
                FiscalMutationOperation::query()
                    ->withoutGlobalScopes()
                    ->sole()
                    ->confirmed_by_user,
            );
            self::assertNull(
                FgtsDigitalRun::query()
                    ->withoutGlobalScopes()
                    ->findOrFail($preview->id)
                    ->request_vault_object_id,
            );
        } finally {
            $this->cleanupChildren($children);
            foreach ([
                $claimedByParent,
                $claimedByChild,
                $releaseByParent,
                $releaseByChild,
            ] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function test_concurrent_session_import_keeps_one_ready_session(): void
    {
        [$tenantId, $clientId] = $this->createTenantAndClient();
        [$claimedByParent, $claimedByChild] = $this->socketPair();
        [$releaseByParent, $releaseByChild] = $this->socketPair();
        [$firstResultParent, $firstResultChild] = $this->socketPair();
        $firstApplicationName = 'fgts-session-first-'
            .strtolower(Str::random(8));
        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            self::fail('Não foi possível iniciar a primeira importação.');
        }
        if ($firstPid === 0) {
            fclose($claimedByParent);
            fclose($releaseByParent);
            fclose($firstResultParent);
            DB::purge();
            DB::statement(
                "SET application_name TO '{$firstApplicationName}'",
            );

            try {
                $session = $this->storeSession(
                    $tenantId,
                    $clientId,
                    new BlockingFgtsSessionStore(
                        $claimedByChild,
                        $releaseByChild,
                    ),
                    'first',
                );
                $outcome = 'stored:'.$session->id;
            } catch (\Throwable $exception) {
                $outcome = 'error:'.$exception::class;
            }

            fwrite($firstResultChild, $outcome);
            fclose($firstResultChild);
            exit(0);
        }

        fclose($claimedByChild);
        fclose($releaseByChild);
        fclose($firstResultChild);
        $secondPid = null;
        $secondResultParent = null;
        $firstReaped = false;
        $secondReaped = false;
        try {
            stream_set_timeout($claimedByParent, 5);
            self::assertSame(
                (string) $firstPid,
                trim((string) fgets($claimedByParent)),
            );

            [$secondResultParent, $secondResultChild] = $this->socketPair();
            $secondApplicationName = 'fgts-session-second-'
                .strtolower(Str::random(8));
            $secondPid = pcntl_fork();
            if ($secondPid === -1) {
                self::fail('Não foi possível iniciar a segunda importação.');
            }
            if ($secondPid === 0) {
                fclose($secondResultParent);
                fclose($claimedByParent);
                fclose($releaseByParent);
                fclose($firstResultParent);
                DB::purge();
                DB::statement(
                    "SET application_name TO '{$secondApplicationName}'",
                );

                try {
                    $session = $this->storeSession(
                        $tenantId,
                        $clientId,
                        new ImmediateFgtsObjectStore,
                        'second',
                    );
                    $outcome = 'stored:'.$session->id;
                } catch (\Throwable $exception) {
                    $outcome = 'error:'.$exception::class;
                }

                fwrite($secondResultChild, $outcome);
                fclose($secondResultChild);
                exit(0);
            }
            fclose($secondResultChild);

            $this->assertConnectionWaitsForLock($secondApplicationName);
            fwrite($releaseByParent, '1');
            fclose($releaseByParent);

            stream_set_timeout($firstResultParent, 10);
            stream_set_timeout($secondResultParent, 10);
            $results = [
                stream_get_contents($firstResultParent),
                stream_get_contents($secondResultParent),
            ];
            $firstExitCode = $this->childExitCode($firstPid);
            $firstReaped = true;
            $secondExitCode = $this->childExitCode($secondPid);
            $secondReaped = true;
            self::assertSame(0, $firstExitCode);
            self::assertSame(0, $secondExitCode);
            foreach ($results as $result) {
                self::assertStringStartsWith('stored:', $result);
            }

            self::assertSame(
                1,
                DB::table('fgts_digital_sessions')
                    ->where('status', FgtsDigitalSessionStatus::Ready->value)
                    ->count(),
            );
            self::assertSame(
                1,
                DB::table('fgts_digital_sessions')
                    ->where('status', FgtsDigitalSessionStatus::Revoked->value)
                    ->count(),
            );
        } finally {
            if (is_resource($releaseByParent)) {
                @fwrite($releaseByParent, '1');
                fclose($releaseByParent);
            }
            foreach ([
                $claimedByParent,
                $firstResultParent,
                $secondResultParent,
            ] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $firstReaped) {
                $this->reapChild($firstPid);
            }
            if (is_int($secondPid) && ! $secondReaped) {
                $this->reapChild($secondPid);
            }
        }
    }

    public function test_failed_preview_cleanup_keeps_reference_for_retry(): void
    {
        [
            $tenant,
            $user,
            $preview,
            $token,
            $privateRequest,
        ] = $this->createPreview();
        $this->app->instance(
            SecureObjectStore::class,
            new FailingDeleteFgtsRequestStore($privateRequest),
        );

        $result = app(FgtsDigitalPortalService::class)
            ->authorizeEmission(
                $tenant,
                $preview,
                $user,
                $token,
                (string) $preview->confirmation_phrase,
            );

        self::assertFalse($result['reused']);
        self::assertNotNull(
            FgtsDigitalRun::query()
                ->withoutGlobalScopes()
                ->findOrFail($preview->id)
                ->request_vault_object_id,
        );
        self::assertSame(
            1,
            FgtsDigitalRun::query()
                ->withoutGlobalScopes()
                ->where('operation', FgtsDigitalOperation::EmitGuide->value)
                ->count(),
        );
    }

    /**
     * @return array{
     *     Tenant,
     *     User,
     *     FgtsDigitalRun,
     *     string,
     *     string
     * }
     */
    private function createPreview(): array
    {
        [$tenantId, $clientId] = $this->createTenantAndClient();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Administrador de teste',
        ]);
        $token = Str::random(48);
        $private = [
            'competence_period_key' => '2026-07',
            'guide_type' => FgtsDigitalGuideType::Monthly->value,
        ];
        ksort($private);
        $privateRequest = json_encode(
            $private,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $digest = hash('sha256', $privateRequest);
        $mutation = FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenantId,
                'client_id' => $clientId,
                'requested_by' => $userId,
                'idempotency_key' => 'fgts-preflight|concurrent',
                'logical_key' => 'fgts-digital|concurrent',
                'correlation_id' => 'fgts-concurrent',
                'environment' => SerproEnvironment::Production,
                'solution_code' => 'FGTS_DIGITAL',
                'service_code' => 'GUIAS',
                'operation_code' => 'EMITIR_GUIA',
                'operation_key' => 'fgts_digital.emitir_guia',
                'status' => FiscalMutationStatus::Pending,
                'confirmation_phrase' => 'EMITIR FGTS 2026-07 MONTHLY',
                'confirmation_required' => true,
                'confirmed_by_user' => false,
            ]);
        $preview = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenantId,
                'client_id' => $clientId,
                'requested_by' => $userId,
                'fiscal_mutation_operation_id' => $mutation->id,
                'operation' => FgtsDigitalOperation::Preview,
                'guide_type' => FgtsDigitalGuideType::Monthly,
                'status' => FgtsDigitalRunStatus::Previewed,
                'idempotency_key' => 'fgts-preview|concurrent',
                'request_digest' => $digest,
                'request_vault_object_id' => str_repeat('P', 26),
                'preview_token_hash' => hash('sha256', $token),
                'confirmation_phrase' => 'EMITIR FGTS 2026-07 MONTHLY',
                'preview_expires_at' => now()->addMinutes(5),
                'request_sanitized' => $private,
                'correlation_id' => 'fgts-concurrent',
            ]);

        return [
            Tenant::query()->findOrFail($tenantId),
            User::query()->findOrFail($userId),
            $preview,
            $token,
            $privateRequest,
        ];
    }

    /** @return array{int, int} */
    private function createTenantAndClient(): array
    {
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Tenant '.Str::random(8),
        ]);
        $clientId = DB::table('clients')->insertGetId([
            'tenant_id' => $tenantId,
        ]);

        return [$tenantId, $clientId];
    }

    private function storeSession(
        int $tenantId,
        int $clientId,
        SecureObjectStore $vault,
        string $fingerprint,
    ): FgtsDigitalSession {
        return (new FgtsDigitalSessionStore($vault))->store(
            $tenantId,
            $clientId,
            FgtsDigitalCredentialSource::Client,
            hash('sha256', $fingerprint),
            'EMPREGADOR',
            hash('sha256', 'target'),
            [
                'cookies' => [[
                    'name' => 'session',
                    'value' => $fingerprint,
                    'domain' => '.gov.br',
                    'path' => '/',
                ]],
                'origins' => [[
                    'origin' => 'https://fgtsdigital.sistema.gov.br',
                    'localStorage' => [],
                ]],
            ],
        );
    }

    /** @param list<array<string, mixed>> $children @return list<string> */
    private function collectChildResults(array &$children): array
    {
        $results = [];
        foreach ($children as $index => $child) {
            stream_set_timeout($child['result'], 10);
            $results[] = stream_get_contents($child['result']);
            fclose($child['result']);
            self::assertSame(0, $this->childExitCode($child['pid']));
            $children[$index]['reaped'] = true;
        }

        return $results;
    }

    /** @param list<array<string, mixed>> $children */
    private function cleanupChildren(array $children): void
    {
        foreach ($children as $child) {
            if (is_resource($child['start'])) {
                @fwrite($child['start'], '1');
                fclose($child['start']);
            }
            foreach (['ready', 'result'] as $stream) {
                if (is_resource($child[$stream])) {
                    fclose($child[$stream]);
                }
            }
            if (! $child['reaped']) {
                $this->reapChild($child['pid']);
            }
        }
    }

    private function assertConnectionWaitsForLock(
        string $applicationName,
    ): void {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $waitEventType = DB::table('pg_catalog.pg_stat_activity')
                ->where('application_name', $applicationName)
                ->value('wait_event_type');
            if ($waitEventType === 'Lock') {
                return;
            }

            usleep(20_000);
        }

        self::fail(
            "A conexão {$applicationName} não aguardou o lock esperado.",
        );
    }

    private function childExitCode(int $pid): int
    {
        $status = $this->reapChild($pid);
        if ($status === null || ! pcntl_wifexited($status)) {
            return -1;
        }

        return pcntl_wexitstatus($status);
    }

    private function reapChild(
        int $pid,
        float $graceSeconds = 1.0,
    ): ?int {
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

    private function pollChildExit(
        int $pid,
        float $timeoutSeconds,
    ): ?int {
        $deadline = hrtime(true)
            + (int) ($timeoutSeconds * 1_000_000_000);

        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                return $status;
            }
            if ($waited === -1
                && pcntl_get_last_error() !== PCNTL_EINTR) {
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
            $table->timestampsTz();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestampsTz();
        });
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->timestampsTz();
        });
        Schema::create(
            'fiscal_mutation_operations',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->string('idempotency_key', 160);
                $table->string('logical_key', 200);
                $table->string('correlation_id', 64);
                $table->string('environment', 20);
                $table->string('solution_code', 80);
                $table->string('service_code', 120);
                $table->string('operation_code', 120);
                $table->string('operation_key', 160);
                $table->string('status', 32);
                $table->string('confirmation_phrase', 120)->nullable();
                $table->boolean('confirmation_required')->default(true);
                $table->boolean('confirmed_by_user')->default(false);
                $table->timestampTz('confirmed_at')->nullable();
                $table->timestampsTz();
                $table->unique(['tenant_id', 'idempotency_key']);
            },
        );
        Schema::create(
            'fgts_digital_runs',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger(
                    'fiscal_mutation_operation_id',
                )->nullable();
                $table->string('operation', 32);
                $table->string('guide_type', 24)->nullable();
                $table->string('status', 40);
                $table->string('idempotency_key', 160);
                $table->string('request_digest', 64);
                $table->string('request_vault_object_id', 26)->nullable();
                $table->string('preview_token_hash', 64)->nullable();
                $table->string('confirmation_phrase', 160)->nullable();
                $table->timestampTz('preview_expires_at')->nullable();
                $table->jsonb('request_sanitized')->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->timestampsTz();
                $table->unique(['tenant_id', 'idempotency_key']);
            },
        );
        Schema::create(
            'fgts_digital_sessions',
            function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('representation_id')->nullable();
                $table->string('credential_source', 16);
                $table->string('credential_fingerprint', 64);
                $table->string('profile_type', 32);
                $table->string('target_identifier_hash', 64);
                $table->string('contract_version', 16);
                $table->string('status', 32);
                $table->string('vault_object_id', 26)->nullable();
                $table->timestampTz('expires_at');
                $table->timestampsTz();
            },
        );
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            throw new RuntimeException(
                'Não foi possível criar o canal local do teste.',
            );
        }

        return $pair;
    }
}

final class BlockingFgtsRequestStore implements SecureObjectStore
{
    /**
     * @param  resource  $claimed
     * @param  resource  $release
     */
    public function __construct(
        private readonly string $privateRequest,
        private $claimed,
        private $release,
    ) {}

    public function put(string $plaintext, array $metadata = []): string
    {
        return substr(
            str_pad('R'.getmypid(), 26, '0'),
            0,
            26,
        );
    }

    public function get(string $objectId, array $metadata = []): string
    {
        fwrite($this->claimed, (string) getmypid()."\n");
        stream_set_timeout($this->release, 5);
        if (fread($this->release, 1) !== '1') {
            throw new RuntimeException(
                'Timeout aguardando liberação da prévia.',
            );
        }

        return $this->privateRequest;
    }

    public function delete(string $objectId): void {}

    public function exists(string $objectId): bool
    {
        return true;
    }
}

final class BlockingFgtsSessionStore implements SecureObjectStore
{
    /**
     * @param  resource  $claimed
     * @param  resource  $release
     */
    public function __construct(
        private $claimed,
        private $release,
    ) {}

    public function put(string $plaintext, array $metadata = []): string
    {
        fwrite($this->claimed, (string) getmypid()."\n");
        stream_set_timeout($this->release, 5);
        if (fread($this->release, 1) !== '1') {
            throw new RuntimeException(
                'Timeout aguardando liberação da sessão.',
            );
        }

        return substr(
            str_pad('S'.getmypid(), 26, '0'),
            0,
            26,
        );
    }

    public function get(string $objectId, array $metadata = []): string
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function delete(string $objectId): void {}

    public function exists(string $objectId): bool
    {
        return true;
    }
}

final class ImmediateFgtsObjectStore implements SecureObjectStore
{
    public function put(string $plaintext, array $metadata = []): string
    {
        return substr(
            str_pad('I'.getmypid(), 26, '0'),
            0,
            26,
        );
    }

    public function get(string $objectId, array $metadata = []): string
    {
        throw new RuntimeException('Operação não usada no teste.');
    }

    public function delete(string $objectId): void {}

    public function exists(string $objectId): bool
    {
        return true;
    }
}

final class FailingDeleteFgtsRequestStore implements SecureObjectStore
{
    public function __construct(
        private readonly string $privateRequest,
    ) {}

    public function put(string $plaintext, array $metadata = []): string
    {
        return str_repeat('R', 26);
    }

    public function get(string $objectId, array $metadata = []): string
    {
        return $this->privateRequest;
    }

    public function delete(string $objectId): void
    {
        throw new RuntimeException('Falha simulada na limpeza.');
    }

    public function exists(string $objectId): bool
    {
        return true;
    }
}
