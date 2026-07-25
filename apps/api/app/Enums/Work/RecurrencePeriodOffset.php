<?php

namespace App\Enums\Work;

/**
 * Defasagem do Período gerado em relação ao período civil do disparo.
 */
enum RecurrencePeriodOffset: string
{
    /** Período recém-encerrado (padrão). */
    case Previous = 'PREVIOUS';

    /** Período civil corrente no momento do disparo. */
    case Current = 'CURRENT';
}
