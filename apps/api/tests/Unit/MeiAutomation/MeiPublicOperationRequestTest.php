<?php

namespace Tests\Unit\MeiAutomation;

use App\Enums\OfficeRole;
use App\Http\Middleware\EnsureOfficeContext;
use App\Http\Requests\Fiscal\Mei\ConsultDasnHistoryRequest;
use App\Http\Requests\Fiscal\Mei\ConsultMeiDebtRequest;
use App\Http\Requests\Fiscal\Mei\GenerateMeiDasRequest;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MeiPublicOperationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_trigger_queries_or_generate_das(): void
    {
        [$viewer] = $this->actor(OfficeRole::Viewer);

        $this->assertFalse($this->request(ConsultMeiDebtRequest::class, $viewer)->authorize());
        $this->assertFalse($this->request(ConsultDasnHistoryRequest::class, $viewer)->authorize());
        $this->assertFalse($this->request(GenerateMeiDasRequest::class, $viewer)->authorize());
    }

    public function test_operator_can_query_but_cannot_generate_das(): void
    {
        [$operator] = $this->actor(OfficeRole::Operator);

        $this->assertTrue($this->request(ConsultMeiDebtRequest::class, $operator)->authorize());
        $this->assertTrue($this->request(ConsultDasnHistoryRequest::class, $operator)->authorize());
        $this->assertFalse($this->request(GenerateMeiDasRequest::class, $operator)->authorize());
    }

    public function test_admin_can_generate_das_and_contract_rejects_tenant_override(): void
    {
        [$admin] = $this->actor(OfficeRole::Admin);
        $request = $this->request(GenerateMeiDasRequest::class, $admin);
        $request->attributes->set(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED, true);

        $validator = Validator::make([
            'client_id' => 1,
            'competencies' => ['2026-01'],
            'output_format' => 'PDF',
            'confirmed' => true,
            'idempotency_key' => 'mei-das-contract-1',
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($request->authorize());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('office_id', $validator->errors()->toArray());
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();

        return [$user, $office];
    }

    /** @param class-string<GenerateMeiDasRequest|ConsultMeiDebtRequest|ConsultDasnHistoryRequest> $class */
    private function request(string $class, User $user): GenerateMeiDasRequest|ConsultMeiDebtRequest|ConsultDasnHistoryRequest
    {
        $request = $class::create('/internal-test', 'POST');
        $request->setContainer($this->app);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
