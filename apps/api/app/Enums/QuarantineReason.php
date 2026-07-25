<?php

namespace App\Enums;

enum QuarantineReason: string
{
    case UnmatchedIssuer = 'UNMATCHED_ISSUER';
    case AutXmlTagMissing = 'AUTXML_TAG_MISSING';
    case AutXmlTagDivergent = 'AUTXML_TAG_DIVERGENT';
    case EnrollmentMissing = 'ENROLLMENT_MISSING';
    case OrphanEvent = 'ORPHAN_EVENT';
    case BytesDiverge = 'BYTES_DIVERGE';
    case SchemaIncomplete = 'SCHEMA_INCOMPLETE';
    case UnknownSchema = 'UNKNOWN_SCHEMA';
    case OfficeAlsoRecipient = 'OFFICE_ALSO_RECIPIENT';
    case SummaryOnly = 'SUMMARY_ONLY';
    /** CT-e principal do próprio emitente no DistDFe do cliente (contrato: não distribui). */
    case UnexpectedOwnIssuerDocument = 'UNEXPECTED_OWN_ISSUER_DOCUMENT';
    /** CNPJ consultado não casa com nenhum papel elegível CT-e. */
    case UnmatchedFiscalRole = 'UNMATCHED_FISCAL_ROLE';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case ProtocolMismatch = 'PROTOCOL_MISMATCH';
    case UnsupportedModel = 'UNSUPPORTED_MODEL';
    case PendingImport = 'PENDING_IMPORT';

    public function label(): string
    {
        return match ($this) {
            self::UnmatchedIssuer => 'Emitente sem vínculo no escritório',
            self::AutXmlTagMissing => 'Tag autXML ausente',
            self::AutXmlTagDivergent => 'Tag autXML divergente do CNPJ consultado',
            self::EnrollmentMissing => 'Estabelecimento sem enrollment autXML',
            self::OrphanEvent => 'Evento sem documento-pai',
            self::BytesDiverge => 'Bytes divergentes para a mesma chave',
            self::SchemaIncomplete => 'Schema/XML incompleto',
            self::UnknownSchema => 'Versão de schema desconhecida',
            self::OfficeAlsoRecipient => 'Escritório também é destinatário',
            self::SummaryOnly => 'Apenas resumo (resNFe)',
            self::UnexpectedOwnIssuerDocument => 'CT-e do próprio emitente no DistDFe do cliente',
            self::UnmatchedFiscalRole => 'Papel fiscal CT-e não identificado',
            self::InvalidSignature => 'Assinatura inválida',
            self::ProtocolMismatch => 'Protocolo/ambiente divergente',
            self::UnsupportedModel => 'Modelo CT-e não suportado nesta projeção',
            self::PendingImport => 'Aguardando importação do original',
        };
    }
}
