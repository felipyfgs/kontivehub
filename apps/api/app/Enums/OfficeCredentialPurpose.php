<?php

namespace App\Enums;

enum OfficeCredentialPurpose: string
{
    /** Finalidade legada e vínculo de purpose link: DistDFe autXML. */
    case NfeAutXmlDistDfe = 'NFE_AUTXML_DISTDFE';

    /** Vínculo de finalidade: assinatura do Termo de Autorização SERPRO. */
    case SerproTermSigning = 'SERPRO_TERM_SIGNING';

    /**
     * Material físico canônico e-CNPJ A1 do escritório (no máximo um ACTIVE).
     * Finalidades usam office_credential_purpose_links apontando para esta credencial.
     */
    case CanonicalECnpjA1 = 'CANONICAL_ECNPJ_A1';

    public function label(): string
    {
        return match ($this) {
            self::NfeAutXmlDistDfe => 'DistDFe autXML (escritório)',
            self::SerproTermSigning => 'Assinatura do Termo SERPRO',
            self::CanonicalECnpjA1 => 'Credencial canônica e-CNPJ A1',
        };
    }

    public function isPurposeLink(): bool
    {
        return match ($this) {
            self::NfeAutXmlDistDfe, self::SerproTermSigning => true,
            self::CanonicalECnpjA1 => false,
        };
    }
}
