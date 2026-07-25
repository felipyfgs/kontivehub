<?php

namespace Tests\Unit\DTO\Fiscal;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FiscalAdapterRequestTest extends TestCase
{
    public function test_accepts_a_fully_coherent_context(): void
    {
        [$office, $client, $run, $competence] = $this->context();

        $request = $this->request($office, $client, $run, $competence);

        self::assertSame($office, $request->office);
        self::assertSame($client, $request->client);
        self::assertSame($run, $request->run);
        self::assertSame($competence, $request->competence);
    }

    #[DataProvider('missingIdentityProvider')]
    public function test_rejects_missing_operational_identity(string $target): void
    {
        [$office, $client, $run, $competence] = $this->context();
        match ($target) {
            'office' => $office->id = null,
            'client' => $client->id = null,
            'run' => $run->id = null,
        };

        $this->expectException(InvalidArgumentException::class);
        $this->request($office, $client, $run, $competence);
    }

    /** @return iterable<string, array{string}> */
    public static function missingIdentityProvider(): iterable
    {
        yield 'office sem id' => ['office'];
        yield 'cliente sem id' => ['client'];
        yield 'run sem id' => ['run'];
    }

    #[DataProvider('tenantMismatchProvider')]
    public function test_rejects_tenant_or_contributor_mismatch(string $target): void
    {
        [$office, $client, $run, $competence] = $this->context();
        match ($target) {
            'client.office_id' => $client->office_id = 99,
            'run.office_id' => $run->office_id = 99,
            'run.client_id' => $run->client_id = 99,
            'competence.office_id' => $competence->office_id = 99,
            'competence.client_id' => $competence->client_id = 99,
            'competence.id' => $competence->id = 99,
        };

        $this->expectException(InvalidArgumentException::class);
        $this->request($office, $client, $run, $competence);
    }

    /** @return iterable<string, array{string}> */
    public static function tenantMismatchProvider(): iterable
    {
        yield 'cliente de outro office' => ['client.office_id'];
        yield 'run de outro office' => ['run.office_id'];
        yield 'run de outro cliente' => ['run.client_id'];
        yield 'competência de outro office' => ['competence.office_id'];
        yield 'competência de outro cliente' => ['competence.client_id'];
        yield 'competência diferente da run' => ['competence.id'];
    }

    public function test_rejects_competence_missing_from_request_when_run_requires_it(): void
    {
        [$office, $client, $run] = $this->context();

        $this->expectException(InvalidArgumentException::class);
        $this->request($office, $client, $run, null);
    }

    public function test_rejects_competence_when_run_has_none(): void
    {
        [$office, $client, $run, $competence] = $this->context();
        $run->competence_id = null;

        $this->expectException(InvalidArgumentException::class);
        $this->request($office, $client, $run, $competence);
    }

    #[DataProvider('coordinateMismatchProvider')]
    public function test_rejects_operation_coordinate_or_trigger_mismatch(string $target): void
    {
        [$office, $client, $run, $competence] = $this->context();
        match ($target) {
            'system_code' => $run->system_code = 'OTHER_SYSTEM',
            'service_code' => $run->service_code = 'OTHER_SERVICE',
            'operation_code' => $run->operation_code = 'OTHER_OPERATION',
            'trigger' => $run->trigger = FiscalTrigger::Scheduled,
        };

        $this->expectException(InvalidArgumentException::class);
        $this->request($office, $client, $run, $competence);
    }

    /** @return iterable<string, array{string}> */
    public static function coordinateMismatchProvider(): iterable
    {
        yield 'sistema' => ['system_code'];
        yield 'serviço' => ['service_code'];
        yield 'operação' => ['operation_code'];
        yield 'trigger' => ['trigger'];
    }

    /** @return array{Office, Client, FiscalMonitoringRun, FiscalCompetence} */
    private function context(): array
    {
        $office = new Office;
        $office->id = 7;

        $client = new Client;
        $client->forceFill(['id' => 11, 'office_id' => 7]);

        $run = new FiscalMonitoringRun;
        $run->forceFill([
            'id' => 13,
            'office_id' => 7,
            'client_id' => 11,
            'competence_id' => 17,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'CONSULTAR',
            'trigger' => FiscalTrigger::Manual,
        ]);

        $competence = new FiscalCompetence;
        $competence->forceFill(['id' => 17, 'office_id' => 7, 'client_id' => 11]);

        return [$office, $client, $run, $competence];
    }

    private function request(
        Office $office,
        Client $client,
        FiscalMonitoringRun $run,
        ?FiscalCompetence $competence,
    ): FiscalAdapterRequest {
        return new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'CONSULTAR',
            trigger: FiscalTrigger::Manual,
            competence: $competence,
        );
    }
}
