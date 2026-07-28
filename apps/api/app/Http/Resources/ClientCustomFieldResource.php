<?php

namespace App\Http\Resources;

use App\Models\ClientCustomField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientCustomField */
final class ClientCustomFieldResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientCustomField $field */
        $field = $this->resource;
        $isSecret = $field->type === 'SECRET';

        return [
            'id' => $field->id,
            'label' => $field->label,
            'type' => $field->type,
            'is_active' => (bool) $field->is_active,
            'value' => $isSecret ? null : $field->value_text,
            'has_value' => $isSecret
                ? $field->vault_object_id !== null
                : filled($field->value_text),
        ];
    }
}
