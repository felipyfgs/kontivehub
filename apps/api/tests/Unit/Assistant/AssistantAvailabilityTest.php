<?php

namespace Tests\Unit\Assistant;

use App\Exceptions\AssistantUnavailableException;
use App\Services\Assistant\AssistantAvailability;
use Tests\TestCase;

class AssistantAvailabilityTest extends TestCase
{
    public function test_default_is_disabled(): void
    {
        config([
            'assistant.enabled' => false,
            'assistant.openai.api_key' => '',
        ]);

        $availability = app(AssistantAvailability::class);

        $this->assertFalse($availability->isEnabled());
    }

    public function test_enabled_flag_without_key_remains_unavailable(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => '',
        ]);

        $availability = app(AssistantAvailability::class);

        $this->assertFalse($availability->isEnabled());
        $this->expectException(AssistantUnavailableException::class);
        $this->expectExceptionMessage('ASSISTANT_DISABLED');
        $availability->assertEnabled();
    }

    public function test_enabled_with_key_is_available(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => 'sk-test-not-a-real-key',
        ]);

        $availability = app(AssistantAvailability::class);

        $this->assertTrue($availability->isEnabled());
        $availability->assertEnabled();
    }

    public function test_whitespace_key_is_treated_as_missing(): void
    {
        config([
            'assistant.enabled' => true,
            'assistant.openai.api_key' => '   ',
        ]);

        $this->assertFalse(app(AssistantAvailability::class)->isEnabled());
    }
}
