<?php

namespace App\Http\Requests\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalEmissionData;

final class EmitFgtsDigitalGuideRequest extends AdministerFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string', 'size:48'],
            'confirmation_phrase' => ['required', 'string', 'max:160'],
        ];
    }

    public function emissionData(): FgtsDigitalEmissionData
    {
        $validated = $this->validated();

        return new FgtsDigitalEmissionData(
            previewRunId: (int) $this->route('run'),
            previewToken: (string) $validated['preview_token'],
            confirmationPhrase: (string) $validated['confirmation_phrase'],
        );
    }
}
