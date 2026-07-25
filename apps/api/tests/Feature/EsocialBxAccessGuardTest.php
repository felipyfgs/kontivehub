<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\EsocialBxAccessLedger;
use App\Models\Office;
use App\Services\Esocial\EsocialBxAccessGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EsocialBxAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_esocial.official_bx.daily_access_limit', 10);
        config()->set('fgts_esocial.official_bx.blocked_days', range(1, 7));
        config()->set('fgts_esocial.official_bx.timezone', 'America/Sao_Paulo');
    }

    public function test_operational_window_uses_configured_timezone(): void
    {
        $guard = app(EsocialBxAccessGuard::class);
        config()->set('fgts_esocial.official_bx.timezone', 'Pacific/Kiritimati');

        $guard->assertOperationalWindow(CarbonImmutable::parse('2026-07-07 12:30:00 UTC'));

        $this->expectExceptionObject(new EsocialBxException(
            'ESOCIAL_BX_BLOCKED_WINDOW',
            'O eSocial BX não permite consultas entre os dias 1 e 7.',
            blocked: true,
        ));
        $guard->assertOperationalWindow(CarbonImmutable::parse('2026-08-05 12:00:00 UTC'));
    }

    public function test_reservation_is_atomic_conservative_and_shared_by_employer(): void
    {
        $guard = app(EsocialBxAccessGuard::class);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        $otherOffice = Office::factory()->create();
        $sameEmployer = Client::factory()->forOffice($otherOffice)->create(['root_cnpj' => '48123272']);
        $now = CarbonImmutable::parse('2026-07-15 12:00:00 America/Sao_Paulo');

        for ($index = 0; $index < 9; $index++) {
            $guard->reserve($office, $client, 'restricted', 'IDENTIFIERS_S-1299', "run-{$index}", $now);
        }
        $guard->reserve($otherOffice, $sameEmployer, 'restricted', 'DOWNLOADS_S-1299', 'run-9', $now);

        $this->assertSame(10, $guard->consumedToday($client, 'restricted', $now));
        $this->assertSame(10, $guard->consumedToday($sameEmployer, 'restricted', $now));
        $this->assertSame(10, EsocialBxAccessLedger::query()->withoutGlobalScopes()->count());

        try {
            $guard->reserve($office, $client, 'restricted', 'IDENTIFIERS_S-5013', 'run-10', $now);
            $this->fail('Décimo primeiro acesso deveria falhar antes do egress.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_QUOTA_EXHAUSTED', $exception->stableCode);
            $this->assertTrue($exception->blocked);
        }
        $this->assertSame(10, EsocialBxAccessLedger::query()->withoutGlobalScopes()->count());
    }

    public function test_employer_lock_covers_callback_and_is_released_afterwards(): void
    {
        $guard = app(EsocialBxAccessGuard::class);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $key = 'esocial-bx:restricted:'.$guard->employerHash($client);

        $result = $guard->withEmployerLock($client, 'restricted', function () use ($key): string {
            $competing = Cache::lock($key, 30);
            $this->assertFalse($competing->get());

            return 'held-through-callback';
        });
        $this->assertSame('held-through-callback', $result);

        $after = Cache::lock($key, 30);
        $this->assertTrue($after->get());
        $after->release();
    }

    public function test_existing_lock_tenant_mismatch_and_finish_are_fail_closed(): void
    {
        $guard = app(EsocialBxAccessGuard::class);
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $key = 'esocial-bx:restricted:'.$guard->employerHash($client);
        $held = Cache::lock($key, 30);
        $this->assertTrue($held->get());
        try {
            $guard->withEmployerLock($client, 'restricted', static fn (): null => null);
            $this->fail('Lock concorrente deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_CONCURRENT_REQUEST', $exception->stableCode);
            $this->assertTrue($exception->retryable);
        } finally {
            $held->release();
        }

        try {
            $guard->reserve(
                $otherOffice,
                $client,
                'restricted',
                'IDENTIFIERS_S-1299',
                'tenant-mismatch',
                CarbonImmutable::parse('2026-07-15 12:00:00 America/Sao_Paulo'),
            );
            $this->fail('Office divergente deveria falhar.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_TENANT_MISMATCH', $exception->stableCode);
            $this->assertTrue($exception->blocked);
        }

        $entry = $guard->reserve(
            $office,
            $client,
            'restricted',
            'IDENTIFIERS_S-1299',
            'finish-test',
            CarbonImmutable::parse('2026-07-15 12:00:00 America/Sao_Paulo'),
        );
        $guard->finish($entry, 'FAILED', 503, '301', true);
        $entry->refresh();
        $this->assertSame('FAILED', $entry->status);
        $this->assertSame(503, $entry->http_status);
        $this->assertSame('301', $entry->official_code);
        $this->assertTrue($entry->retryable);
        $this->assertNotNull($entry->finished_at);
    }
}
