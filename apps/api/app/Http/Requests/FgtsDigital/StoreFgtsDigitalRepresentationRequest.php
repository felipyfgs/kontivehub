<?php

namespace App\Http\Requests\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalRepresentationData;
use Carbon\CarbonImmutable;

final class StoreFgtsDigitalRepresentationRequest extends AdministerFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'valid_to' => ['required', 'date', 'after:now'],
            'confirmed' => ['required', 'accepted'],
            'profile_type' => [
                'sometimes',
                'string',
                'in:PROCURADOR_PJ,RESPONSAVEL_LEGAL',
            ],
        ];
    }

    public function representationData(): FgtsDigitalRepresentationData
    {
        $validated = $this->validated();

        return new FgtsDigitalRepresentationData(
            clientId: (int) $validated['client_id'],
            validTo: CarbonImmutable::parse((string) $validated['valid_to']),
            profileType: (string) (
                $validated['profile_type'] ?? 'PROCURADOR_PJ'
            ),
        );
    }
}
