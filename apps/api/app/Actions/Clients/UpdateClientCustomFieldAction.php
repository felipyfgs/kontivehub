<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCustomFieldUpdateData;
use App\Models\Client;
use App\Models\ClientCustomField;
use App\Services\Audit\AuditLogger;

final readonly class UpdateClientCustomFieldAction
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        Client $client,
        ClientCustomField $customField,
        ClientCustomFieldUpdateData $data,
    ): ClientCustomField {
        if ((int) $customField->client_id !== (int) $client->id) {
            abort(404);
        }

        if (array_key_exists('label', $data->attributes)) {
            $customField->label = $data->attributes['label'];
        }
        if (array_key_exists('is_active', $data->attributes)) {
            $customField->is_active = (bool) $data->attributes['is_active'];
        }
        if (array_key_exists('value', $data->attributes) && $customField->type === 'TEXT') {
            $customField->value_text = $data->attributes['value'];
        }
        $customField->save();

        $this->audit->record('client.custom_field.update', 'SUCCESS', $client, [
            'custom_field_id' => $customField->id,
            'fields' => array_keys($data->attributes),
        ]);

        return $customField->refresh();
    }
}
