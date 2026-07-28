<?php

namespace App\Http\Requests\FgtsEsocial;

use App\DTO\Esocial\FgtsEsocialSyncData;

final class ExecuteFgtsEsocialSyncRequest extends OperateFgtsEsocialRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'competence_period_key' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'establishment_id' => ['sometimes', 'nullable', 'integer'],
            'dispatch_job' => ['sometimes', 'boolean'],
            'create_run' => ['sometimes', 'boolean'],
        ];
    }

    public function syncData(): FgtsEsocialSyncData
    {
        $validated = $this->validated();

        return new FgtsEsocialSyncData(
            clientId: (int) $validated['client_id'],
            competencePeriodKey: (string) $validated['competence_period_key'],
            establishmentId: isset($validated['establishment_id'])
                ? (int) $validated['establishment_id']
                : null,
            dispatchJob: false,
            createRun: false,
        );
    }
}
