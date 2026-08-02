<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommunicationOutboundInitiationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_idempotency_key_converges_to_one_conversation_message_and_outbox(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open é necessário para a regressão concorrente PostgreSQL.');
        }

        $connectionName = 'outbound-initiation-fixture-'.Str::lower(Str::random(8));
        Config::set(
            'database.connections.'.$connectionName,
            config('database.connections.'.config('database.default')),
        );
        $fixture = DB::connection($connectionName);
        $now = now();
        $tenantId = (int) $fixture->table('tenants')->insertGetId([
            'name' => 'Tenant outbound concorrente '.Str::ulid(),
            'slug' => 'tenant-outbound-'.Str::lower(Str::ulid()),
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'lifecycle_status' => 'ACTIVE',
            'communication_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inboxAddress = '+5511000000040';
        $inboxId = (int) $fixture->table('communication_inboxes')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Inbox outbound concorrente',
            'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => Crypt::encryptString($inboxAddress),
            'address_hash' => hash('sha256', $inboxAddress),
            'address_masked' => '***0040',
            'status' => InboxStatus::Connected->value,
            'is_enabled' => true,
            'is_default' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contactId = (int) $fixture->table('communication_contacts')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Contato concorrente',
            'is_provisional' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $identityAddress = '+5511999990040';
        $identityId = $this->committedIdentity(
            $fixture,
            $tenantId,
            $contactId,
            $identityAddress,
            $now,
        );
        $temporaryDirectory = sys_get_temp_dir().'/outbound-initiation-'.Str::lower(Str::ulid());
        $this->assertTrue(
            mkdir($temporaryDirectory, 0700) || is_dir($temporaryDirectory),
            'Não foi possível criar o diretório temporário da barreira concorrente.',
        );
        $startFile = $temporaryDirectory.'/start';
        $processes = [];
        $defaultConnection = (string) config('database.default');
        $database = config('database.connections.'.$defaultConnection);
        $workerEnvironment = [
            'PATH' => (string) getenv('PATH'),
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => $defaultConnection,
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'KH_DB_CONNECTION' => $defaultConnection,
            'KH_DB_HOST' => (string) $database['host'],
            'KH_DB_PORT' => (string) $database['port'],
            'KH_DB_DATABASE' => (string) $database['database'],
            'KH_DB_USERNAME' => (string) $database['username'],
            'KH_DB_PASSWORD' => (string) $database['password'],
        ];
        $workerScript = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$databaseConnection = (string) getenv('KH_DB_CONNECTION');
config([
    'database.default' => $databaseConnection,
    'database.connections.'.$databaseConnection.'.host' => getenv('KH_DB_HOST'),
    'database.connections.'.$databaseConnection.'.port' => getenv('KH_DB_PORT'),
    'database.connections.'.$databaseConnection.'.database' => getenv('KH_DB_DATABASE'),
    'database.connections.'.$databaseConnection.'.username' => getenv('KH_DB_USERNAME'),
    'database.connections.'.$databaseConnection.'.password' => getenv('KH_DB_PASSWORD'),
    'communication.enabled' => true,
    'communication.gateway.enabled' => true,
    'communication.outbound_conversation.enabled' => true,
    'communication.outbound_conversation.kill_switch' => false,
    'communication.outbound_conversation.allow_all_tenants' => true,
    'communication.outbound_conversation.allowed_tenant_ids' => [],
]);
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Event::fake();
try {
    [$tenantId, $contactId, $identityId, $inboxId, $readyFile, $startFile] = array_slice($argv, 1);
    touch($readyFile);
    $deadline = microtime(true) + 10;
    while (! is_file($startFile) && microtime(true) < $deadline) {
        clearstatcache(true, $startFile);
        usleep(10_000);
    }
    clearstatcache(true, $startFile);
    if (! is_file($startFile)) {
        throw new RuntimeException('Barreira concorrente expirou.');
    }
    $tenant = App\Models\Tenant::query()->findOrFail((int) $tenantId);
    app(App\Support\CurrentTenant::class)->bindSystem($tenant);
    $data = new App\DTO\Communication\MessageCreationData(
        body: 'Primeiro contato concorrente',
        internalNote: false,
        requestedKind: App\Enums\Communication\MessageKind::Text,
        ptt: false,
        gif: false,
        richPayload: [],
        replyToMessageId: null,
        idempotencyKey: 'start-concurrent-0001',
        upload: null,
        receiptMessageId: null,
        outboundInitiation: true,
    );
    $result = app(App\Actions\Communication\StartConversationAction::class)->handle(
        (int) $contactId,
        (int) $identityId,
        (int) $inboxId,
        $data,
    );
    fwrite(STDOUT, json_encode([
        'conversation_id' => (int) $result['conversation']->id,
        'message_id' => (int) $result['message']->id,
        'status' => (int) $result['status'],
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': worker_failed');
    exit(1);
}
PHP;

        try {
            foreach ([0, 1] as $index) {
                $readyFile = $temporaryDirectory.'/ready-'.$index;
                $stdoutFile = $temporaryDirectory.'/stdout-'.$index;
                $stderrFile = $temporaryDirectory.'/stderr-'.$index;
                $process = proc_open(
                    [
                        PHP_BINARY,
                        '-r',
                        $workerScript,
                        (string) $tenantId,
                        (string) $contactId,
                        (string) $identityId,
                        (string) $inboxId,
                        $readyFile,
                        $startFile,
                    ],
                    [
                        1 => ['file', $stdoutFile, 'w'],
                        2 => ['file', $stderrFile, 'w'],
                    ],
                    $pipes,
                    base_path(),
                    $workerEnvironment,
                );
                $this->assertIsResource($process);
                $processes[$index] = [$process, $stdoutFile, $stderrFile];
            }

            $deadline = microtime(true) + 10;
            while ((! is_file($temporaryDirectory.'/ready-0')
                || ! is_file($temporaryDirectory.'/ready-1'))
                && microtime(true) < $deadline) {
                clearstatcache(true, $temporaryDirectory.'/ready-0');
                clearstatcache(true, $temporaryDirectory.'/ready-1');
                usleep(10_000);
            }
            clearstatcache(true, $temporaryDirectory.'/ready-0');
            clearstatcache(true, $temporaryDirectory.'/ready-1');
            $this->assertFileExists($temporaryDirectory.'/ready-0');
            $this->assertFileExists($temporaryDirectory.'/ready-1');
            touch($startFile);

            $results = [];
            foreach ($processes as $index => [$process, $stdoutFile, $stderrFile]) {
                $deadline = microtime(true) + 30;
                do {
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);
                if ($status['running']) {
                    proc_terminate($process);
                    proc_close($process);
                    unset($processes[$index]);
                    $this->fail('Worker concorrente '.$index.' não finalizou em 30s (possível deadlock).');
                }
                $closedCode = proc_close($process);
                $exitCode = $closedCode >= 0 ? $closedCode : (int) $status['exitcode'];
                unset($processes[$index]);
                $stdout = file_get_contents($stdoutFile);
                $stderr = file_get_contents($stderrFile);
                $this->assertSame(0, $exitCode, trim((string) $stderr."\n".(string) $stdout));
                $result = json_decode((string) $stdout, true, 8, JSON_THROW_ON_ERROR);
                $this->assertIsArray($result);
                $results[] = $result;
            }

            $this->assertSame($results[0]['conversation_id'], $results[1]['conversation_id']);
            $this->assertSame($results[0]['message_id'], $results[1]['message_id']);
            $this->assertEqualsCanonicalizing([200, 202], array_column($results, 'status'));
            $this->assertSame(1, $fixture->table('communication_conversations')
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $inboxId)
                ->where('identity_id', $identityId)
                ->whereNull('merged_into_conversation_id')
                ->count());
            $this->assertSame(1, $fixture->table('communication_messages')
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $inboxId)
                ->where('direction', 'OUTBOUND')
                ->count());
            $this->assertSame(1, $fixture->table('communication_outbox_entries')
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $inboxId)
                ->count());
        } finally {
            foreach ($processes as [$process]) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
            try {
                foreach ([
                    'communication_outbox_entries',
                    'communication_attachments',
                    'communication_events',
                    'communication_conversation_unread_messages',
                    'communication_conversation_read_states',
                    'communication_messages',
                    'communication_conversation_clients',
                    'communication_conversation_labels',
                    'communication_conversations',
                    'communication_inbox_identity_profiles',
                    'communication_inbox_members',
                    'communication_inboxes',
                    'communication_identities',
                    'communication_contacts',
                ] as $table) {
                    $fixture->table($table)->where('tenant_id', $tenantId)->delete();
                }
                $fixture->table('tenants')->where('id', $tenantId)->delete();
            } catch (\Throwable) {
                // A limpeza nunca mascara a falha concorrente original.
            }
            DB::purge($connectionName);
            foreach (glob($temporaryDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($temporaryDirectory);
        }
    }

    private function committedIdentity(
        ConnectionInterface $connection,
        int $tenantId,
        int $contactId,
        string $address,
        \DateTimeInterface $now,
    ): int {
        return (int) $connection->table('communication_identities')->insertGetId([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'channel' => CommunicationChannel::WhatsApp->value,
            'address_encrypted' => Crypt::encryptString($address),
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
