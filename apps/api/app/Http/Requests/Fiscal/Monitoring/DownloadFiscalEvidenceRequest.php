<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Enums\TenantPermission;
use App\Models\FiscalEvidenceArtifact;
use App\Services\Authorization\TenantAuthorization;
use App\Services\FiscalMonitoring\FiscalQueryService;
use App\Support\CurrentTenant;

final class DownloadFiscalEvidenceRequest extends FiscalMonitoringViewRequest
{
    private ?FiscalEvidenceArtifact $resolvedArtifact = null;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $artifact = $this->artifact();

        return app(TenantAuthorization::class)->allows(
            $this->actor(),
            TenantPermission::FiscalMonitoringView,
            $artifact,
        );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function artifact(): FiscalEvidenceArtifact
    {
        if ($this->resolvedArtifact instanceof FiscalEvidenceArtifact) {
            return $this->resolvedArtifact;
        }

        $tenant = app(CurrentTenant::class)->tenant();
        $artifact = app(FiscalQueryService::class)->evidence(
            $tenant,
            (int) $this->route('evidence'),
        );

        if (! $artifact instanceof FiscalEvidenceArtifact) {
            abort(404, 'Evidência não encontrada.');
        }

        return $this->resolvedArtifact = $artifact;
    }
}
