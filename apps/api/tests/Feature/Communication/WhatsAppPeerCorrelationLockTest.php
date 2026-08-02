<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Jobs\Communication\ReconcileInboxIdentityProfileJob;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Services\Communication\WhatsAppPeerCorrelationService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WhatsAppPeerCorrelationLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_aliases_acquire_transaction_locks_in_deterministic_order(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Atendimento',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $remotePn = '+559992032709';
        $lid = 'lid:149865032093945';
        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $query->bindings;
            }
        });

        DB::transaction(fn () => app(WhatsAppPeerCorrelationService::class)->correlate(
            $inbox,
            $remotePn,
            [$lid, $remotePn],
            false,
            now(),
        ));

        $this->assertSame([
            $this->lockBindings($inbox, $remotePn),
            $this->lockBindings($inbox, $lid),
        ], $locks);
        $this->assertDatabaseCount('communication_contacts', 1);
        $this->assertDatabaseCount('communication_identities', 2);
        $this->assertDatabaseCount('communication_conversations', 1);
        Queue::assertPushedOn(
            'communication',
            ReconcileInboxIdentityProfileJob::class,
            fn ($job): bool => $job->tenantId === $tenant->id
                && $job->inboxId === $inbox->id,
        );
    }

    public function test_existing_equivalence_classes_lock_every_member_in_id_order(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Atendimento',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $remotePn = '+559992032709';
        $lid = 'lid:149865032093945';
        $lidContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'is_provisional' => true,
            'is_active' => true,
        ]);
        $pnContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'is_provisional' => true,
            'is_active' => true,
        ]);
        $lidRoot = $this->identity($tenant->id, $lidContact->id, $lid);
        $lidChild = $this->identity(
            $tenant->id,
            $lidContact->id,
            'lid:149865032093946',
            $lidRoot->id,
        );
        $pnRoot = $this->identity($tenant->id, $pnContact->id, $remotePn);
        $pnChild = $this->identity(
            $tenant->id,
            $pnContact->id,
            '+559992032708',
            $pnRoot->id,
        );
        $locks = [];
        DB::listen(static function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $query->bindings;
            }
        });

        DB::transaction(fn () => app(WhatsAppPeerCorrelationService::class)->correlate(
            $inbox,
            $remotePn,
            [$lid, $remotePn],
            false,
            now(),
        ));

        $memberIds = [$lidRoot->id, $lidChild->id, $pnRoot->id, $pnChild->id];
        sort($memberIds, SORT_NUMERIC);
        $this->assertSame([
            $this->lockBindings($inbox, $remotePn),
            $this->lockBindings($inbox, $lid),
            ...array_map(
                fn (int $id): array => $this->memberLockBindings($inbox, $id),
                $memberIds,
            ),
        ], $locks);
        $this->assertNull($pnRoot->refresh()->canonical_identity_id);
        foreach ([$lidRoot, $lidChild, $pnChild] as $donor) {
            $this->assertSame($pnRoot->id, $donor->refresh()->canonical_identity_id);
        }
    }

    public function test_disjoint_identity_classes_sharing_a_contact_converge_concurrently(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open é necessário para a regressão concorrente PostgreSQL.');
        }

        $connectionName = 'peer-correlation-fixture-'.Str::lower(Str::random(8));
        Config::set(
            'database.connections.'.$connectionName,
            config('database.connections.'.config('database.default')),
        );
        $fixture = DB::connection($connectionName);
        $now = now();
        $tenantId = (int) $fixture->table('tenants')->insertGetId([
            'name' => 'Tenant concorrência '.Str::ulid(),
            'slug' => 'tenant-peer-lock-'.Str::lower(Str::ulid()),
            'is_active' => true,
            'timezone' => 'America/Sao_Paulo',
            'lifecycle_status' => 'ACTIVE',
            'communication_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $inboxId = (int) $fixture->table('communication_inboxes')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Inbox concorrente',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected->value,
            'is_enabled' => true,
            'is_default' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $curatedContactId = (int) $fixture->table('communication_contacts')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'Contato curado',
            'is_provisional' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sharedContactId = (int) $fixture->table('communication_contacts')->insertGetId([
            'tenant_id' => $tenantId,
            'is_provisional' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $otherContactId = (int) $fixture->table('communication_contacts')->insertGetId([
            'tenant_id' => $tenantId,
            'is_provisional' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $firstPair = [
            'lid:149865032093945',
            '+559992032709',
        ];
        $secondPair = [
            'lid:149865032093946',
            '+559992032708',
        ];
        $identityIds = [
            $this->committedIdentity($fixture, $tenantId, $sharedContactId, $firstPair[0], $now),
            $this->committedIdentity($fixture, $tenantId, $curatedContactId, $firstPair[1], $now),
            $this->committedIdentity($fixture, $tenantId, $sharedContactId, $secondPair[0], $now),
            $this->committedIdentity($fixture, $tenantId, $otherContactId, $secondPair[1], $now),
        ];
        $temporaryDirectory = sys_get_temp_dir().'/peer-correlation-'.Str::lower(Str::ulid());
        mkdir($temporaryDirectory, 0700);
        $startFile = $temporaryDirectory.'/start';
        $processes = [];
        $database = config('database.connections.'.config('database.default'));
        $workerEnvironment = [
            'PATH' => (string) getenv('PATH'),
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
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
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => getenv('KH_DB_HOST'),
    'database.connections.pgsql.port' => getenv('KH_DB_PORT'),
    'database.connections.pgsql.database' => getenv('KH_DB_DATABASE'),
    'database.connections.pgsql.username' => getenv('KH_DB_USERNAME'),
    'database.connections.pgsql.password' => getenv('KH_DB_PASSWORD'),
]);
try {
    [$inboxId, $lid, $phone, $readyFile, $startFile] = array_slice($argv, 1);
    touch($readyFile);
    $deadline = microtime(true) + 10;
    while (! is_file($startFile) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($startFile)) {
        throw new RuntimeException('Barreira concorrente expirou.');
    }
    $inbox = App\Models\CommunicationInbox::query()
        ->withoutGlobalScopes()
        ->findOrFail((int) $inboxId);
    Illuminate\Support\Facades\DB::transaction(
        fn () => app(App\Services\Communication\WhatsAppPeerCorrelationService::class)
            ->correlate($inbox, $phone, [$lid, $phone], false, now()),
        3,
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage());
    exit(1);
}
PHP;

        try {
            foreach ([$firstPair, $secondPair] as $index => [$lid, $phone]) {
                $readyFile = $temporaryDirectory.'/ready-'.$index;
                $stdoutFile = $temporaryDirectory.'/stdout-'.$index;
                $stderrFile = $temporaryDirectory.'/stderr-'.$index;
                $process = proc_open(
                    [
                        PHP_BINARY,
                        '-r',
                        $workerScript,
                        (string) $inboxId,
                        $lid,
                        $phone,
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
                usleep(10_000);
            }
            $this->assertFileExists($temporaryDirectory.'/ready-0');
            $this->assertFileExists($temporaryDirectory.'/ready-1');
            touch($startFile);
            $workerResults = [];
            foreach ($processes as $index => [$process, $stdoutFile, $stderrFile]) {
                $exitCode = proc_close($process);
                unset($processes[$index]);
                $stdout = file_get_contents($stdoutFile);
                $stderr = file_get_contents($stderrFile);
                $workerResults[] = [$exitCode, $stdout, $stderr];
            }
            foreach ($workerResults as [$exitCode, $stdout, $stderr]) {
                $this->assertSame(
                    0,
                    $exitCode,
                    trim((string) $stderr."\n".(string) $stdout),
                );
            }

            $contactIds = [$curatedContactId, $sharedContactId, $otherContactId];
            $rootContactId = (int) $fixture->table('communication_contacts')
                ->whereIn('id', $contactIds)
                ->whereNull('merged_into_contact_id')
                ->soleValue('id');
            $this->assertSame($curatedContactId, $rootContactId);
            $this->assertSame(
                [$rootContactId],
                $fixture->table('communication_identities')
                    ->whereIn('id', $identityIds)
                    ->pluck('contact_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            );
            $this->assertSame(2, $fixture->table('communication_contacts')
                ->whereIn('id', $contactIds)
                ->where('merged_into_contact_id', $rootContactId)
                ->where('is_active', false)
                ->count());
        } finally {
            foreach ($processes as [$process]) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
            $fixture->table('tenants')->where('id', $tenantId)->delete();
            DB::purge($connectionName);
            foreach (glob($temporaryDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($temporaryDirectory);
        }
    }

    /** @return array{0:int,1:int} */
    private function lockBindings(CommunicationInbox $inbox, string $alias): array
    {
        $bytes = substr(hash(
            'sha256',
            $inbox->tenant_id.'|WHATSAPP|'.$alias,
            true,
        ), 0, 8);
        /** @var array{scope:int,alias:int} $parts */
        $parts = unpack('Nscope/Nalias', $bytes);

        return [
            $this->signedInt32($parts['scope']),
            $this->signedInt32($parts['alias']),
        ];
    }

    /** @return array{0:int,1:int} */
    private function memberLockBindings(CommunicationInbox $inbox, int $identityId): array
    {
        $bytes = substr(hash(
            'sha256',
            $inbox->tenant_id.'|WHATSAPP|member|'.$identityId,
            true,
        ), 0, 8);
        /** @var array{scope:int,member:int} $parts */
        $parts = unpack('Nscope/Nmember', $bytes);

        return [
            $this->signedInt32($parts['scope']),
            $this->signedInt32($parts['member']),
        ];
    }

    private function identity(
        int $tenantId,
        int $contactId,
        string $address,
        ?int $canonicalIdentityId = null,
    ): CommunicationIdentity {
        return CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'canonical_identity_id' => $canonicalIdentityId,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
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

    private function signedInt32(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }
}
