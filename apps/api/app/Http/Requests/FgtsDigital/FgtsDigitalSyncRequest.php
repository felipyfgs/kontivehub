<?php

namespace App\Http\Requests\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalSyncData;

abstract class FgtsDigitalSyncRequest extends OperateFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'parameters' => ['sometimes', 'array'],
        ];
    }

    public function syncData(): FgtsDigitalSyncData
    {
        $validated = $this->validated();

        return new FgtsDigitalSyncData(
            clientId: (int) $validated['client_id'],
            parameters: $validated['parameters'] ?? [],
        );
    }
}
