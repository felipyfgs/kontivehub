<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\RecipientMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AutomationPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'module_key' => $this->module_key,
            'submodule_key' => $this->submodule_key,
            'inbox_id' => $this->inbox_id,
            'inbox_name' => $this->relationLoaded('inbox') ? $this->inbox?->name : null,
            'is_enabled' => (bool) $this->is_enabled,
            'send_day' => (int) $this->send_day,
            'send_time' => substr((string) $this->send_time, 0, 5),
            'timezone' => $this->timezone,
            'recipient_mode' => ($this->recipient_mode instanceof RecipientMode
                ? $this->recipient_mode
                : RecipientMode::Primary)->value,
            'template_key' => $this->template_key,
            'template_version' => $this->template_version,
            'lock_version' => (int) $this->lock_version,
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }
}
