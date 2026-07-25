<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\AssistantChatService;
use ReflectionMethod;
use Tests\TestCase;

class AssistantChatServiceSanitizeTest extends TestCase
{
    public function test_sanitize_tool_results_without_args_does_not_explode(): void
    {
        $service = app(AssistantChatService::class);
        $method = new ReflectionMethod(AssistantChatService::class, 'sanitizeToolResults');
        $method->setAccessible(true);

        $sanitized = $method->invoke($service, [
            [
                'status' => 'pending_approval',
                'approval_token' => 'secret-token',
                'tool_name' => 'create_process_template',
            ],
            [
                'status' => 'ok',
                'result' => ['id' => 1],
                'args' => null,
            ],
            [
                'status' => 'ok',
                'args' => [
                    'name' => 'Modelo',
                    'office_id' => 99,
                    'api_key' => 'should-go',
                ],
            ],
        ]);

        $this->assertSame('pending_approval', $sanitized[0]['status']);
        $this->assertArrayNotHasKey('approval_token', $sanitized[0]);
        $this->assertNull($sanitized[1]['args'] ?? null);
        $this->assertSame('Modelo', $sanitized[2]['args']['name']);
        $this->assertArrayNotHasKey('office_id', $sanitized[2]['args']);
        $this->assertArrayNotHasKey('api_key', $sanitized[2]['args']);
    }
}
