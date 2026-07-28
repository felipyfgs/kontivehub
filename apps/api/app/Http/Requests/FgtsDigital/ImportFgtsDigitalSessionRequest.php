<?php

namespace App\Http\Requests\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalSessionImportData;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;

#[FailOnUnknownFields(false)]
final class ImportFgtsDigitalSessionRequest extends AdministerFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'storage_state' => ['required', 'array'],
            'storage_state.cookies' => ['required', 'array'],
            'storage_state.origins' => ['required', 'array'],
        ];
    }

    public function importData(): FgtsDigitalSessionImportData
    {
        $validated = $this->validated();

        return new FgtsDigitalSessionImportData(
            clientId: (int) $validated['client_id'],
            storageState: $validated['storage_state'],
        );
    }
}
