<?php

namespace App\Enums;

/**
 * Pendências documentais externas que bloqueiam promoção produtiva.
 * Preenchidas por evidência sanitizada (ticket, e-mail, parecer) — sem segredos.
 */
enum SerproExternalGateKind: string
{
    case OauthEndpointDivergence = 'OAUTH_ENDPOINT_DIVERGENCE';
    case TermoXsdOfficial = 'TERMO_XSD_OFFICIAL';
    case CnpjAlphanumericSerialization = 'CNPJ_ALPHANUMERIC_SERIALIZATION';
    case ContractVigencyTariff = 'CONTRACT_VIGENCY_TARIFF';
    case SoftwareHouseLegalModel = 'SOFTWARE_HOUSE_LEGAL_MODEL';
    case OpsRolesRpoRto = 'OPS_ROLES_RPO_RTO';
    case OfficialClarificationRequired = 'OFFICIAL_CLARIFICATION_REQUIRED';

    public function label(): string
    {
        return match ($this) {
            self::OauthEndpointDivergence => 'Divergência curl Área do Cliente vs /authenticate',
            self::TermoXsdOfficial => 'XSD oficial do Termo de Autorização',
            self::CnpjAlphanumericSerialization => 'Serialização CNPJ alfanumérico no Termo/Eventos',
            self::ContractVigencyTariff => 'Vigência contratual e tabela/ciclo tarifário',
            self::SoftwareHouseLegalModel => 'Modelo jurídico software-house',
            self::OpsRolesRpoRto => 'Responsáveis, on-call, RPO/RTO e custódia A1',
            self::OfficialClarificationRequired => 'Esclarecimento oficial SERPRO pendente',
        };
    }
}
