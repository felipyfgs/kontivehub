<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientDetailData;
use App\Models\Client;
use App\Models\Establishment;
use App\Services\Clients\CaptureEligibilityService;
use App\Services\Integra\ClientProcuracaoValidityResolver;

final readonly class GetClientDetailAction
{
    public function __construct(
        private CaptureEligibilityService $eligibility,
        private ClientProcuracaoValidityResolver $procuracoes,
    ) {}

    public function __invoke(Client $client): ClientDetailData
    {
        $client->load([
            'credential',
            'procuracaoSyncs' => fn ($query) => $query->where(
                'environment',
                (string) config('serpro.default_environment', 'TRIAL'),
            ),
            'establishments' => fn ($query) => $query->orderBy('cnpj'),
            'contacts' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('name'),
            'customFields' => fn ($query) => $query->orderBy('id'),
            'categories' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            'workDepartment',
        ]);

        $captureEligibility = [];
        foreach ($client->establishments as $establishment) {
            /** @var Establishment $establishment */
            $captureEligibility[$establishment->id] = $this->eligibility->evaluate($establishment);
        }

        return new ClientDetailData(
            client: $client,
            captureEligibility: $captureEligibility,
            procuracaoProjection: $this->procuracoes->resolve(
                $client->procuracaoSyncs->first(),
            ),
        );
    }
}
