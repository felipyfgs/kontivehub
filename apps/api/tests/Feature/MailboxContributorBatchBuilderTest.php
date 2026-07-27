<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\Tenant;
use App\Services\Integra\Mailbox\MailboxContributorBatchBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxContributorBatchBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_deterministic_authorized_batches_without_cross_tenant_leak(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $eligible = Client::factory()->for($tenant)->create();
        $inactive = Client::factory()->for($tenant)->create(['is_active' => false]);
        $unauthorized = Client::factory()->for($tenant)->create();
        $foreign = Client::factory()->for($otherTenant)->create();

        $eligibleEstablishment = Establishment::factory()->forClient($eligible, '11222333000181')->create();
        Establishment::factory()->forClient($inactive, '11365521000169')->create();
        Establishment::factory()->forClient($unauthorized, '11444777000161')->create();
        Establishment::factory()->forClient($foreign, '19131243000197')->create();
        ClientProcuracaoSync::factory()->forClient($eligible)->authorized()->create();
        ClientProcuracaoSync::factory()->forClient($inactive)->authorized()->create();
        ClientProcuracaoSync::factory()->forClient($foreign)->authorized()->create();

        $builder = app(MailboxContributorBatchBuilder::class);

        $this->assertSame([[(string) $eligibleEstablishment->cnpj]], $builder->batches($tenant, 1));
        $this->assertSame(
            [(string) $eligibleEstablishment->cnpj => (int) $eligible->id],
            $builder->clientMap($tenant),
        );
    }

    public function test_preserves_valid_alphanumeric_cnpj_as_text(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $cnpj = $this->alphanumericCnpj('ABCDEF120001');
        Establishment::factory()->forClient($client, $cnpj)->create();
        ClientProcuracaoSync::factory()->forClient($client)->authorized()->create();

        $this->assertSame([[$cnpj]], app(MailboxContributorBatchBuilder::class)->batches($tenant));
    }

    private function alphanumericCnpj(string $base): string
    {
        $digit = static function (string $value, array $weights): string {
            $sum = 0;
            foreach (str_split($value) as $index => $char) {
                $sum += (ord($char) - 48) * $weights[$index];
            }
            $mod = $sum % 11;

            return (string) ($mod < 2 ? 0 : 11 - $mod);
        };
        $d1 = $digit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $base.$d1.$digit($base.$d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    }
}
