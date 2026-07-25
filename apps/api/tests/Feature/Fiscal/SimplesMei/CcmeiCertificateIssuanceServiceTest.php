<?php

namespace Tests\Feature\Fiscal\SimplesMei;

use App\Contracts\SecureObjectStore;
use App\Contracts\SerproOperationExecutor;
use App\Models\Client;
use App\Models\Office;
use App\Services\Fiscal\SimplesMei\CcmeiCertificateIssuanceProjector;
use App\Services\Fiscal\SimplesMei\CcmeiCertificateIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CcmeiCertificateIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_rejects_a_hard_deleted_client_before_calling_serpro(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $client->delete();

        $operations = $this->createMock(SerproOperationExecutor::class);
        $operations->expects($this->never())->method('execute');

        $service = new CcmeiCertificateIssuanceService(
            $operations,
            $this->app->make(CcmeiCertificateIssuanceProjector::class),
            $this->createStub(SecureObjectStore::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cliente não encontrado no escritório atual.');

        $service->issue($office, $client);
    }
}
