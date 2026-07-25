<?php

namespace App\Enums;

enum AuthorCertificateMode: string
{
    /** Assinatura externa (browser/token A3 ou A1 local). */
    case ExternalSignature = 'EXTERNAL_SIGNATURE';

    /** A1 gerenciado pela plataforma (consentimento + cofre). */
    case ManagedA1 = 'MANAGED_A1';

    /** A3 interativo — nunca automatizado. */
    case InteractiveA3 = 'INTERACTIVE_A3';
}
