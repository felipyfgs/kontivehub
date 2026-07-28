<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantUnavailableException;

final class AssistantAvailability
{
    public function isEnabled(): bool
    {
        if (! (bool) config('assistant.enabled')) {
            return false;
        }

        $key = config('assistant.openai.api_key');

        return is_string($key) && trim($key) !== '';
    }

    public function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AssistantUnavailableException;
        }
    }
}
