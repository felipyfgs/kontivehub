<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Finalidades distintas no SecureObjectStore (AAD metadata `purpose`).
 * Nunca reutilizar purpose entre titulares/fluxos diferentes.
 */
enum SecureObjectPurpose: string
{
    /** PFX + senha do e-CNPJ contratante (software house / contrato SERPRO global). */
    case SerproContractorPfx = 'SERPRO_CONTRACTOR_PFX';

    /** Consumer Key/Secret OAuth2 do contrato SERPRO. */
    case SerproOauthSecrets = 'SERPRO_OAUTH_SECRETS';

    /** Bearer público controlado do gateway oficial de demonstração. */
    case SerproTrialGatewayBearer = 'SERPRO_TRIAL_GATEWAY_BEARER';

    /** Bearer/JWT temporários do contratante (cache cifrado). */
    case SerproBearerToken = 'SERPRO_BEARER_TOKEN';

    /** Token autenticar_procurador (por escritório/autor). */
    case SerproProcuradorToken = 'SERPRO_PROCURADOR_TOKEN';

    /** Termo de Autorização XML assinado (imutável). */
    case SerproTermoXml = 'SERPRO_TERMO_XML';

    /** Evidência oficial de monitoramento fiscal (resposta/artefato imutável). */
    case FiscalEvidence = 'FISCAL_EVIDENCE';

    /** Identificador DEFIS protegido para consulta específica (nunca público). */
    case FiscalDefisReference = 'FISCAL_DEFIS_REFERENCE';

    /** Corpo de mensagem da Caixa Postal (conteúdo fiscal restrito). */
    case MailboxMessageBody = 'MAILBOX_MESSAGE_BODY';

    /** Anexo de mensagem da Caixa Postal (conteúdo fiscal restrito). */
    case MailboxAttachment = 'MAILBOX_ATTACHMENT';

    /** Documento de guia fiscal (PDF/bytes oficiais — tenant-scoped). */
    case TaxGuideDocument = 'TAX_GUIDE_DOCUMENT';

    /** Evidência oficial de pagamento de guia (independente da emissão). */
    case TaxGuidePaymentEvidence = 'TAX_GUIDE_PAYMENT_EVIDENCE';

    /** Estado de sessão autorizada do portal FGTS Digital (cookies/storage cifrados). */
    case FgtsDigitalSession = 'FGTS_DIGITAL_SESSION';

    /** Seleção privada de débitos/empregados vinculada a uma execução FGTS Digital. */
    case FgtsDigitalRequest = 'FGTS_DIGITAL_REQUEST';

    /** Evidência de tarefa operacional (PDF/imagem/texto — tenant-scoped, sem fiscal). */
    case WorkTaskEvidence = 'WORK_TASK_EVIDENCE';

    /** Mídia privada de conversa; persistência cifrada em chunks. */
    case CommunicationMedia = 'COMMUNICATION_MEDIA';

    public function label(): string
    {
        return match ($this) {
            self::SerproContractorPfx => 'Certificado contratante SERPRO',
            self::SerproOauthSecrets => 'Credenciais OAuth SERPRO',
            self::SerproTrialGatewayBearer => 'Bearer do gateway Trial SERPRO',
            self::SerproBearerToken => 'Token Bearer/JWT contratante',
            self::SerproProcuradorToken => 'Token do procurador',
            self::SerproTermoXml => 'Termo de Autorização XML',
            self::FiscalEvidence => 'Evidência fiscal de monitoramento',
            self::FiscalDefisReference => 'Referência protegida de declaração DEFIS',
            self::MailboxMessageBody => 'Corpo de mensagem Caixa Postal',
            self::MailboxAttachment => 'Anexo Caixa Postal',
            self::TaxGuideDocument => 'Documento de guia fiscal',
            self::TaxGuidePaymentEvidence => 'Evidência de pagamento de guia',
            self::FgtsDigitalSession => 'Sessão autorizada do FGTS Digital',
            self::FgtsDigitalRequest => 'Seleção privada do FGTS Digital',
            self::WorkTaskEvidence => 'Evidência de tarefa operacional',
            self::CommunicationMedia => 'Mídia privada de atendimento',
        };
    }

    /**
     * @return array<string, scalar|null>
     */
    public function aadBase(array $extra = []): array
    {
        if (array_key_exists('purpose', $extra)) {
            throw new InvalidArgumentException('secure_object_purpose_reserved');
        }

        return ['purpose' => $this->value, ...$extra];
    }
}
