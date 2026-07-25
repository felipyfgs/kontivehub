<?php

namespace App\Services\Work\Demo;

use App\Models\Office;
use LogicException;
use RuntimeException;

/**
 * Guard de ambiente/tenant para fixtures operacionais demonstrativas.
 * Validação MUST ocorrer antes de qualquer mutação no banco.
 */
final class WorkDemoEnvironmentGuard
{
    /**
     * @throws LogicException|RuntimeException
     */
    public function assertCanSeed(?Office $office = null): Office
    {
        if (! $this->isAllowedEnvironment()) {
            throw new LogicException(
                'OperationalWorkDemoSeeder recusado: ambiente "'.app()->environment()
                .'" não permite fixtures demonstrativas (somente local/testing).'
            );
        }

        if (! (bool) config('work_demo.enabled', true)) {
            throw new LogicException(
                'OperationalWorkDemoSeeder recusado: work_demo.enabled=false.'
            );
        }

        $slug = $this->demoOfficeSlug();
        $resolved = $office ?? Office::query()->where('slug', $slug)->first();

        if ($resolved === null) {
            throw new RuntimeException(
                "Office demo com slug \"{$slug}\" não encontrado. Execute o DatabaseSeeder base antes."
            );
        }

        if ($resolved->slug !== $slug) {
            throw new LogicException(
                'OperationalWorkDemoSeeder recusado: office "'.$resolved->slug
                ."\" não é o tenant demo configurado (\"{$slug}\")."
            );
        }

        return $resolved;
    }

    public function isAllowedEnvironment(): bool
    {
        $allowed = config('work_demo.allowed_environments', ['local', 'testing']);

        return app()->environment(is_array($allowed) ? $allowed : ['local', 'testing']);
    }

    public function demoOfficeSlug(): string
    {
        return (string) config('work_demo.office_slug', 'demo');
    }

    public function sentinelOfficeSlug(): string
    {
        return (string) config('work_demo.sentinel_office_slug', 'demo-work-sentinel');
    }

    public function fixtureMarker(): string
    {
        return (string) config('work_demo.fixture_marker', '[demo-work-fixture]');
    }

    public function keyPrefix(): string
    {
        return (string) config('work_demo.key_prefix', 'DEMO_WORK');
    }

    public function watermark(): string
    {
        return (string) config('work_demo.watermark', 'DEMONSTRAÇÃO — SEM VALIDADE FISCAL');
    }

    public function manifestVersion(): string
    {
        return (string) config('work_demo.manifest_version', '1.0.0');
    }
}
