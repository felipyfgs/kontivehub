<?php

namespace App\Http\Requests\FgtsDigital;

final class ShowFgtsDigitalReadinessRequest extends ViewFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
        ];
    }

    public function clientId(): int
    {
        return (int) $this->validated('client_id');
    }
}
