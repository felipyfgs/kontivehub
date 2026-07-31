<?php

namespace Tests\Unit\Communication;

use App\Enums\TenantPermission;
use App\Models\CommunicationCannedResponse;
use App\Models\Tenant;
use App\Services\Communication\Flows\FlowGraphCanonicalizer;
use App\Services\Communication\Flows\FlowGraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommunicationFlowGraphValidatorTest extends TestCase
{
    use RefreshDatabase;

    private FlowGraphValidator $validator;

    private FlowGraphCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canonicalizer = new FlowGraphCanonicalizer;
        $this->validator = new FlowGraphValidator($this->canonicalizer);
    }

    public function test_accepts_allowlisted_dag(): void
    {
        $tenant = Tenant::factory()->create();
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => 'Olá']],
                ['id' => 'h', 'type' => 'handoff', 'data' => []],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'm'],
                ['id' => 'e2', 'source' => 'm', 'target' => 'h'],
                ['id' => 'e3', 'source' => 'h', 'target' => 'e'],
            ],
        ];

        $result = $this->validator->validate($graph, (int) $tenant->id);

        $this->assertTrue($result->valid);
        $this->assertSame($this->canonicalizer->digest($graph), $result->digest);
        $this->assertSame([], $result->errors);
    }

    public function test_rejects_cycle(): void
    {
        $tenant = Tenant::factory()->create();
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'a', 'type' => 'message', 'data' => ['body' => 'A']],
                ['id' => 'b', 'type' => 'message', 'data' => ['body' => 'B']],
            ],
            'edges' => [
                ['source' => 's', 'target' => 'a'],
                ['source' => 'a', 'target' => 'b'],
                ['source' => 'b', 'target' => 'a'],
            ],
        ];

        $result = $this->validator->validate($graph, (int) $tenant->id);

        $this->assertFalse($result->valid);
        $this->assertContains('cycle_detected', array_column($result->errors, 'code'));
    }

    public function test_rejects_forbidden_webhook_and_ai_nodes(): void
    {
        $tenant = Tenant::factory()->create();
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'w', 'type' => 'webhook', 'data' => ['url' => 'https://evil.example']],
                ['id' => 'ai', 'type' => 'ai', 'data' => ['prompt' => 'x']],
            ],
            'edges' => [
                ['source' => 's', 'target' => 'w'],
                ['source' => 'w', 'target' => 'ai'],
            ],
        ];

        $result = $this->validator->validate($graph, (int) $tenant->id);

        $this->assertFalse($result->valid);
        $codes = array_column($result->errors, 'code');
        $this->assertTrue(
            in_array('node_type_forbidden', $codes, true)
            || in_array('forbidden_content', $codes, true)
            || in_array('forbidden_field', $codes, true),
            'Esperava rejeição de webhook/IA; codes='.implode(',', $codes),
        );
    }

    public function test_digest_is_stable_under_key_reordering(): void
    {
        $a = [
            'edges' => [
                ['target' => 'm', 'source' => 's', 'id' => 'e1'],
            ],
            'nodes' => [
                ['data' => ['body' => 'Oi'], 'type' => 'message', 'id' => 'm'],
                ['id' => 's', 'type' => 'start', 'data' => []],
            ],
        ];
        $b = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => 'Oi']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'm'],
            ],
        ];

        // Same semantic content with different object key order → same digest after canonicalize of each node/edge object.
        // List order of nodes differs, so digests differ — prove object-key stability on identical structure:
        $sameA = [
            'nodes' => [
                ['type' => 'start', 'id' => 's', 'data' => []],
                ['data' => ['body' => 'Oi'], 'id' => 'm', 'type' => 'message'],
            ],
            'edges' => [
                ['target' => 'm', 'id' => 'e1', 'source' => 's'],
            ],
        ];
        $sameB = [
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'm'],
            ],
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => 'Oi']],
            ],
        ];

        $this->assertSame(
            $this->canonicalizer->digest($sameA),
            $this->canonicalizer->digest($sameB),
        );
        $this->assertNotSame(
            $this->canonicalizer->digest($a),
            $this->canonicalizer->digest($b),
        );
    }

    public function test_rejects_canned_from_other_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreign = Tenant::factory()->create();
        $canned = CommunicationCannedResponse::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id,
            'title' => 'X',
            'shortcut' => 'x',
            'body_encrypted' => 'corpo',
            'is_active' => true,
            'lock_version' => 1,
        ]);

        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'q', 'type' => 'quick_reply', 'data' => ['canned_response_id' => $canned->id]],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['source' => 's', 'target' => 'q'],
                ['source' => 'q', 'target' => 'e'],
            ],
        ];

        $result = $this->validator->validate($graph, (int) $tenant->id);

        $this->assertFalse($result->valid);
        $this->assertContains('canned_out_of_tenant', array_column($result->errors, 'code'));
    }

    public function test_permission_and_flag_defaults(): void
    {
        $this->assertSame('communication.manage_flows', TenantPermission::CommunicationManageFlows->value);
        $this->assertContains(
            TenantPermission::CommunicationManageFlows->value,
            TenantPermission::orderedValues(),
        );
        $this->assertFalse((bool) config('communication.flows.enabled'));
    }
}
