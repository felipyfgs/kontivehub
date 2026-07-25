<?php

namespace App\Services\FiscalMonitoring\ModulePortfolio;

use App\Enums\FiscalDataOrigin;
use App\Models\Office;

/**
 * Proveniência sanitizada (DEMO/SIMULATED/LIVE).
 * Sem expor seeder, vault, paths ou credenciais.
 * Seeder/manifesto pleno fica na task 3.x — aqui só o resolver mínimo seguro.
 */
final class DataOriginResolver
{
    public function resolve(Office $office): FiscalDataOrigin
    {
        $env = (string) app()->environment();
        $demoSlug = (string) config('fiscal_monitoring.demo.office_slug', 'demo');
        $officeSlug = (string) ($office->slug ?? '');

        if (in_array($env, ['local', 'testing'], true)
            && $demoSlug !== ''
            && $officeSlug !== ''
            && strcasecmp($officeSlug, $demoSlug) === 0
        ) {
            return FiscalDataOrigin::Demo;
        }

        if (filter_var(config('fiscal_monitoring.demo.force_simulated', false), FILTER_VALIDATE_BOOL)
            && in_array($env, ['local', 'testing'], true)
        ) {
            return FiscalDataOrigin::Simulated;
        }

        if (strtoupper((string) config('serpro.default_environment', 'TRIAL')) === 'TRIAL') {
            return FiscalDataOrigin::Trial;
        }

        return FiscalDataOrigin::Live;
    }
}
