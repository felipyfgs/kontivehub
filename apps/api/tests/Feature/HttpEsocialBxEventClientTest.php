<?php

namespace Tests\Feature;

use App\Contracts\EsocialBxSoapTransport;
use App\Contracts\SecureObjectStore;
use App\DTO\Esocial\EsocialBxHttpResponse;
use App\DTO\Esocial\EsocialFetchRequest;
use App\Enums\CredentialStatus;
use App\Enums\EsocialEventCode;
use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\EsocialBxAccessLedger;
use App\Models\Office;
use App\Services\Esocial\HttpEsocialBxEventClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpEsocialBxEventClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-15 12:00:00-03:00');
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'restricted');
        config()->set('fgts_esocial.kill_switch', false);
        config()->set('fgts_esocial.official_bx.daily_access_limit', 10);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_official_flow_queries_s5013_and_s1299_downloads_and_persists_sanitized_ledger(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        [$pfx, $password] = $this->makePfx();
        $fingerprint = str_repeat('b', 64);
        $store = app(SecureObjectStore::class);
        $objectId = $store->put(json_encode([
            'pfx' => base64_encode($pfx),
            'password' => $password,
        ], JSON_THROW_ON_ERROR), [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'fingerprint' => $fingerprint,
        ]);
        ClientCredential::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'eSocial BX fixture',
            'holder_cnpj' => '48123272000105',
            'fingerprint_sha256' => $fingerprint,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => $objectId,
            'activated_at' => now(),
        ]);

        $transport = new QueueEsocialBxTransport([
            new EsocialBxHttpResponse(200, $this->soap($this->identifiersResult('406'))),
            new EsocialBxHttpResponse(200, $this->soap($this->identifiersResult('201', true))),
            new EsocialBxHttpResponse(200, $this->soap($this->downloadResult())),
        ], $password);
        $this->app->instance(EsocialBxSoapTransport::class, $transport);

        $result = app(HttpEsocialBxEventClient::class)->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: '2026-06',
            correlationId: 'fgts-bx-test',
        ));

        $this->assertTrue($result->success);
        $this->assertTrue($result->partial, 'S-5003 deve permanecer explicitamente fora da automação agregada.');
        $this->assertCount(1, $result->events);
        $this->assertSame('S-1299', $result->events[0]->eventCode->value);
        $this->assertCount(3, $transport->calls);
        $this->assertCount(3, EsocialBxAccessLedger::query()->withoutGlobalScopes()->get());
        $this->assertSame(
            ['SUCCEEDED'],
            EsocialBxAccessLedger::query()->withoutGlobalScopes()->distinct()->pluck('status')->all(),
        );

        $serialized = EsocialBxAccessLedger::query()->withoutGlobalScopes()->firstOrFail()->toPublicArray();
        $encoded = json_encode($serialized, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $encoded);
        $this->assertStringNotContainsString('48123272', $encoded);
        $this->assertArrayNotHasKey('employer_hash', $serialized);
    }

    public function test_empty_official_results_are_successful_without_false_partiality(): void
    {
        [$office, $client, $password] = $this->readyContext();
        $transport = new QueueEsocialBxTransport([
            new EsocialBxHttpResponse(200, $this->soap($this->identifiersResult('406'))),
            new EsocialBxHttpResponse(200, $this->soap($this->identifiersResult('406'))),
        ], $password);
        $this->app->instance(EsocialBxSoapTransport::class, $transport);

        $result = app(HttpEsocialBxEventClient::class)->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: '2026-06',
            eventCodes: [EsocialEventCode::S5013, EsocialEventCode::S1299],
        ));

        $this->assertTrue($result->success);
        $this->assertFalse($result->partial);
        $this->assertSame([], $result->events);
        $this->assertCount(2, $transport->calls);
        $this->assertSame(2, EsocialBxAccessLedger::query()->withoutGlobalScopes()->count());
    }

    public function test_partial_download_is_deduplicated_by_official_event_id(): void
    {
        [$office, $client, $password] = $this->readyContext();
        $transport = new QueueEsocialBxTransport([
            new EsocialBxHttpResponse(200, $this->soap($this->identifiersResult('203', true))),
            new EsocialBxHttpResponse(200, $this->soap($this->duplicateDownloadResult())),
        ], $password);
        $this->app->instance(EsocialBxSoapTransport::class, $transport);

        $result = app(HttpEsocialBxEventClient::class)->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: '2026-06',
            eventCodes: [EsocialEventCode::S1299, EsocialEventCode::S1299],
        ));

        $this->assertTrue($result->success);
        $this->assertTrue($result->partial);
        $this->assertCount(1, $result->events);
        $this->assertCount(2, $transport->calls, 'Evento solicitado em duplicidade não pode duplicar egress.');
    }

    public function test_official_blocker_is_sanitized_and_persisted_without_secret(): void
    {
        [$office, $client, $password] = $this->readyContext();
        $transport = new QueueEsocialBxTransport([
            new EsocialBxHttpResponse(200, $this->soap($this->identifiersResult('405'))),
        ], $password);
        $this->app->instance(EsocialBxSoapTransport::class, $transport);

        $result = app(HttpEsocialBxEventClient::class)->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: '2026-06',
            eventCodes: [EsocialEventCode::S1299],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('ESOCIAL_BX_QUOTA_EXHAUSTED', $result->errorCode);
        $this->assertTrue($result->diagnostics['blocked']);
        $this->assertSame('405', $result->diagnostics['official_code']);
        $entry = EsocialBxAccessLedger::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('BLOCKED', $entry->status);
        $this->assertSame('405', $entry->official_code);
        $encoded = json_encode([$result->diagnostics, $entry->toPublicArray()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $encoded);
    }

    public function test_s5003_without_worker_identifier_never_materializes_pfx_or_calls_transport(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        ClientCredential::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'Metadata only',
            'holder_cnpj' => '48123272000105',
            'fingerprint_sha256' => str_repeat('c', 64),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => 'OBJECT-MUST-NOT-BE-READ',
            'activated_at' => now(),
        ]);
        $transport = new QueueEsocialBxTransport([], 'unused-secret');
        $this->app->instance(EsocialBxSoapTransport::class, $transport);

        $result = app(HttpEsocialBxEventClient::class)->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: '2026-06',
            eventCodes: [EsocialEventCode::S5003],
        ));

        $this->assertTrue($result->success);
        $this->assertTrue($result->partial);
        $this->assertSame([], $result->events);
        $this->assertSame([], $transport->calls);
        $this->assertSame(0, EsocialBxAccessLedger::query()->withoutGlobalScopes()->count());
    }

    public function test_http_failure_releases_flow_with_sanitized_error_and_failed_ledger(): void
    {
        [$office, $client, $password] = $this->readyContext();
        $transport = new QueueEsocialBxTransport([
            new EsocialBxHttpResponse(503, '<untrusted>secret remote body</untrusted>'),
        ], $password);
        $this->app->instance(EsocialBxSoapTransport::class, $transport);

        $result = app(HttpEsocialBxEventClient::class)->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: '2026-06',
            eventCodes: [EsocialEventCode::S1299],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('ESOCIAL_BX_HTTP_ERROR', $result->errorCode);
        $this->assertTrue($result->diagnostics['retryable']);
        $this->assertStringNotContainsString('secret remote body', (string) $result->errorMessage);
        $entry = EsocialBxAccessLedger::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('FAILED', $entry->status);
        $this->assertSame(503, $entry->http_status);
        $this->assertTrue($entry->retryable);
    }

    /** @return array{string,string} */
    private function makePfx(): array
    {
        $password = 'fixture-pfx-password';
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'eSocial BX Fixture'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));

        return [$pfx, $password];
    }

    private function identifiersResult(string $code, bool $withId = false): string
    {
        $result = $withId
            ? '<retornoIdentificadoresEvts><qtdeTotEvtsConsulta>1</qtdeTotEvtsConsulta><identificadoresEvts><identificadorEvt><id>ID12345678901234567890</id><nrRec>1.2.000000000000001</nrRec></identificadorEvt></identificadoresEvts></retornoIdentificadoresEvts>'
            : '';

        return '<eSocial xmlns="urn:identifiers"><retornoConsultaIdentificadoresEvts>'
            .'<status><cdResposta>'.$code.'</cdResposta><descResposta>fixture</descResposta></status>'
            .$result.'</retornoConsultaIdentificadoresEvts></eSocial>';
    }

    private function downloadResult(): string
    {
        return <<<'XML'
<eSocial xmlns="urn:download"><download>
  <status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>
  <retornoSolicDownloadEvts><arquivos><arquivo>
    <status><cdResposta>201</cdResposta><descResposta>Evento encontrado</descResposta></status>
    <evt Id="ID12345678901234567890"><eSocial xmlns="urn:event"><evtFechaEvPer><ideEvento><perApur>2026-06</perApur></ideEvento></evtFechaEvPer></eSocial></evt>
    <rec nrRec="1.2.000000000000001"><eSocial xmlns="urn:receipt"><recibo/></eSocial></rec>
  </arquivo></arquivos></retornoSolicDownloadEvts>
</download></eSocial>
XML;
    }

    private function duplicateDownloadResult(): string
    {
        $found = <<<'XML'
<arquivo>
  <status><cdResposta>201</cdResposta><descResposta>Evento encontrado</descResposta></status>
  <evt Id="ID12345678901234567890"><eSocial xmlns="urn:event"><evtFechaEvPer><ideEvento><perApur>2026-06</perApur></ideEvento></evtFechaEvPer></eSocial></evt>
  <rec nrRec="1.2.000000000000001"><eSocial xmlns="urn:receipt"><recibo/></eSocial></rec>
</arquivo>
XML;

        return '<eSocial xmlns="urn:download"><download>'
            .'<status><cdResposta>201</cdResposta><descResposta>Sucesso</descResposta></status>'
            .'<retornoSolicDownloadEvts><arquivos>'.$found.$found
            .'<arquivo><status><cdResposta>202</cdResposta><descResposta>Ausente</descResposta></status></arquivo>'
            .'</arquivos></retornoSolicDownloadEvts></download></eSocial>';
    }

    /** @return array{Office,Client,string} */
    private function readyContext(): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        [$pfx, $password] = $this->makePfx();
        $fingerprint = hash('sha256', $pfx);
        $store = app(SecureObjectStore::class);
        $objectId = $store->put(json_encode([
            'pfx' => base64_encode($pfx),
            'password' => $password,
        ], JSON_THROW_ON_ERROR), [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'fingerprint' => $fingerprint,
        ]);
        ClientCredential::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'eSocial BX fixture',
            'holder_cnpj' => '48123272000105',
            'fingerprint_sha256' => $fingerprint,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => $objectId,
            'activated_at' => now(),
        ]);

        return [$office, $client, $password];
    }

    private function soap(string $body): string
    {
        return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .$body.'</soap:Body></soap:Envelope>';
    }
}

final class QueueEsocialBxTransport implements EsocialBxSoapTransport
{
    /** @var list<array{endpoint:string,action:string,envelope:string}> */
    public array $calls = [];

    /** @param list<EsocialBxHttpResponse|EsocialBxException> $responses */
    public function __construct(private array $responses, private readonly string $secret) {}

    public function post(
        string $endpoint,
        string $soapAction,
        string $envelope,
        string $pfxBinary,
        string $password,
    ): EsocialBxHttpResponse {
        if ($password !== $this->secret || str_contains($envelope, $this->secret)) {
            throw new \RuntimeException('Credencial ausente ou vazada no envelope de teste.');
        }
        $this->calls[] = ['endpoint' => $endpoint, 'action' => $soapAction, 'envelope' => $envelope];

        $response = array_shift($this->responses) ?? throw new \RuntimeException('Resposta fake não configurada.');
        if ($response instanceof EsocialBxException) {
            throw $response;
        }

        return $response;
    }
}
