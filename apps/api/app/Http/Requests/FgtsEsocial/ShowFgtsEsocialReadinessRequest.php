<?php

namespace App\Http\Requests\FgtsEsocial;

final class ShowFgtsEsocialReadinessRequest extends ViewFgtsEsocialRequest
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
