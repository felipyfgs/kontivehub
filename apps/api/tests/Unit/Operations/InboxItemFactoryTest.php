<?php

namespace Tests\Unit\Operations;

use App\Services\Clients\CaptureEligibilityService;
use App\Services\Operations\Inbox\InboxCapabilities;
use App\Services\Operations\Inbox\InboxItemFactory;
use Tests\TestCase;

final class InboxItemFactoryTest extends TestCase
{
    public function test_cte_item_uses_the_canonical_health_type_path(): void
    {
        $items = new InboxItemFactory(new CaptureEligibilityService);

        $item = $items->cteItem(
            type: 'cte_656',
            title: 'Consulta rejeitada',
            body: 'A consulta retornou rejeição.',
            reasons: ['656'],
            clientId: null,
            establishmentId: null,
            occurredAt: now()->toIso8601String(),
            role: new InboxCapabilities,
            retryAllowed: false,
            cursorId: null,
        );

        $this->assertSame('/health/type/cte_656', $item['links']['health']);
        $this->assertStringNotContainsString('?', $item['links']['health']);
    }

    public function test_cte_item_rejects_an_unknown_health_type(): void
    {
        $items = new InboxItemFactory(new CaptureEligibilityService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tipo de item CT-e não suportado.');

        $items->cteItem(
            type: 'cte_unknown/../../admin',
            title: 'Tipo desconhecido',
            body: 'Item inválido.',
            reasons: [],
            clientId: null,
            establishmentId: null,
            occurredAt: now()->toIso8601String(),
            role: new InboxCapabilities,
            retryAllowed: false,
            cursorId: null,
        );
    }
}
