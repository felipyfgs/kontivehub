<?php

namespace App\Http\Resources\Fiscal;

use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Models\DctfwebDeclaration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DctfwebDeclaration */
final class DctfwebDeclarationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DctfwebDeclaration $declaration */
        $declaration = $this->resource;

        return [
            'id' => $declaration->id,
            'tenant_id' => $declaration->tenant_id,
            'client_id' => $declaration->client_id,
            'competence_id' => $declaration->competence_id,
            'period_key' => $declaration->period_key,
            'category' => $declaration->category?->value
                ?? DctfwebCategory::default()->value,
            'declaration_type' => $declaration->declaration_type,
            'transmission_status' => $declaration->transmission_status?->value,
            'situation' => $declaration->situation?->value,
            'declaration_state' => $declaration->declaration_state?->value
                ?? DctfwebDeclarationState::Unverified->value,
            'no_movement' => $declaration->no_movement,
            'coverage' => $declaration->coverage?->value,
            'receipt_number' => $declaration->receipt_number,
            'transmitted_at' => $declaration->transmitted_at?->toIso8601String(),
            'official_at' => $declaration->official_at?->toIso8601String(),
            'last_productive_consulted_at' => $declaration
                ->last_productive_consulted_at?->toIso8601String(),
            'calendar_verified' => (bool) $declaration->calendar_verified,
            'calendar_version_code' => $declaration->calendar_version_code,
            'due_at' => $declaration->due_at?->toIso8601String(),
            'state_reason' => $declaration->state_reason,
            'evidence_version' => $declaration->evidence_version,
            'payment_status' => $declaration->payment_status?->value,
            'current_snapshot_id' => $declaration->current_snapshot_id,
        ];
    }
}
