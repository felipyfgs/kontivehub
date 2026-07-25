<?php

namespace App\Enums\Work;

/**
 * Regras de prazo do modelo (MVP — dias corridos; sem feriados/dias úteis).
 */
enum DueRuleType: string
{
    /** Dia fixo do mês da competência (1–31; clamp no último dia se inexistente). */
    case FixedDayOfCompetence = 'FIXED_DAY_OF_COMPETENCE';

    /** N dias corridos após o início (dia 1) da competência. */
    case DaysAfterCompetenceStart = 'DAYS_AFTER_COMPETENCE_START';

    /** N dias corridos antes do prazo do processo. */
    case DaysBeforeProcessDue = 'DAYS_BEFORE_PROCESS_DUE';
}
