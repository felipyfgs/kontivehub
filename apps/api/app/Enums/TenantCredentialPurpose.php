<?php

namespace App\Enums;

enum TenantCredentialPurpose: string
{
    /** Vínculo da certificado com DistDFe autXML. */
    case NfeAutXmlDistDfe = 'NFE_AUTXML_DISTDFE';

    /** Vínculo de finalidade: assinatura do Termo de Autorização SERPRO. */
    case SerproTermSigning = 'SERPRO_TERM_SIGNING';

    public function label(): string
    {
        return match ($this) {
            self::NfeAutXmlDistDfe => 'DistDFe autXML (escritório)',
            self::SerproTermSigning => 'Assinatura do Termo SERPRO',
        };
    }
}
