<?php

namespace App\DTO\Communication;

use App\Enums\Communication\RecipientMode;

final readonly class AutomationPolicyData
{
    public function __construct(
        public string $moduleKey,
        public string $submoduleKey,
        public ?int $inboxId,
        public bool $isEnabled,
        public int $sendDay,
        public string $sendTime,
        public string $timezone,
        public RecipientMode $recipientMode,
        public string $templateKey,
        public string $templateVersion,
        public int $lockVersion,
    ) {}

    /** @return array<string, mixed> */
    public function persistenceAttributes(): array
    {
        return [
            'module_key' => $this->moduleKey,
            'submodule_key' => $this->submoduleKey,
            'inbox_id' => $this->inboxId,
            'is_enabled' => $this->isEnabled,
            'send_day' => $this->sendDay,
            'send_time' => $this->sendTime,
            'timezone' => $this->timezone,
            'recipient_mode' => $this->recipientMode->value,
            'template_key' => $this->templateKey,
            'template_version' => $this->templateVersion,
        ];
    }
}
