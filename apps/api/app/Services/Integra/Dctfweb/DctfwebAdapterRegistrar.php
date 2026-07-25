<?php

namespace App\Services\Integra\Dctfweb;

use App\Services\FiscalMonitoring\FiscalAdapterRegistry;
use App\Services\Integra\Dctfweb\Adapters\DctfwebDarfAdapter;
use App\Services\Integra\Dctfweb\Adapters\DctfwebMonitorAdapter;
use App\Services\Integra\Dctfweb\Adapters\DctfwebReciboAdapter;
use App\Services\Integra\Dctfweb\Adapters\DctfwebRelatorioAdapter;
use App\Services\Integra\Dctfweb\Adapters\DctfwebTransmitirAdapter;
use App\Services\Integra\Dctfweb\Adapters\DctfwebXmlAdapter;
use App\Services\Integra\Dctfweb\Adapters\MitApuracaoAdapter;
use App\Services\Integra\Dctfweb\Adapters\MitEncerrarAdapter;
use App\Services\Integra\Dctfweb\Adapters\MitListaApuracoesAdapter;
use App\Services\Integra\Dctfweb\Adapters\MitSituacaoAdapter;
use Illuminate\Contracts\Container\Container;

/** Registra adapters DCTFWeb/MIT no núcleo fiscal. */
final class DctfwebAdapterRegistrar
{
    public function __construct(
        private readonly Container $app,
    ) {}

    public function register(FiscalAdapterRegistry $registry): void
    {
        $classes = [
            DctfwebMonitorAdapter::class,
            DctfwebReciboAdapter::class,
            DctfwebRelatorioAdapter::class,
            DctfwebXmlAdapter::class,
            DctfwebDarfAdapter::class,
            DctfwebTransmitirAdapter::class,
            MitSituacaoAdapter::class,
            MitApuracaoAdapter::class,
            MitListaApuracoesAdapter::class,
            MitEncerrarAdapter::class,
        ];

        foreach ($classes as $class) {
            $registry->register($this->app->make($class));
        }
    }
}
