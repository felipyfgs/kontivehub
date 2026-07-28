<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\MonitoringModuleCommunicationReadData;
use App\Services\Fiscal\Dctfweb\MitCommunicationService;
use App\Services\Fiscal\Fgts\FgtsCommunicationService;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;

final class ReadMonitoringModuleCommunicationAction
{
    public function __construct(
        private readonly SitfisCommunicationService $sitfis,
        private readonly FgtsCommunicationService $fgts,
        private readonly MitCommunicationService $mit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        MonitoringModuleCommunicationReadData $data,
    ): array {
        return $this->service($data->module)->preview(
            $data->tenant,
            $data->client,
        );
    }

    /** @return array<string, mixed> */
    public function tracking(
        MonitoringModuleCommunicationReadData $data,
    ): array {
        return $this->service($data->module)->tracking(
            $data->tenant,
            $data->client,
        );
    }

    private function service(
        string $module,
    ): SitfisCommunicationService|FgtsCommunicationService|MitCommunicationService {
        return match ($module) {
            'sitfis' => $this->sitfis,
            'fgts' => $this->fgts,
            'mit' => $this->mit,
            default => abort(404, 'Módulo de comunicação desconhecido.'),
        };
    }
}
