<?php

namespace App\Enums;

enum AuthorCertificateMode: string
{
    /** Assinatura externa (browser/token A3 ou certificado local). */
    case ExternalSignature = 'EXTERNAL_SIGNATURE';

    /** Certificado gerenciado pela plataforma (consentimento + cofre). */
    case ManagedCertificate = 'MANAGED_CERTIFICATE';

    /** A3 interativo — nunca automatizado. */
    case InteractiveA3 = 'INTERACTIVE_A3';
}
