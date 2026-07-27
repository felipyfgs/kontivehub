<?php

namespace App\Enums;

/**
 * Proveniência de aquisição de documento (múltiplas fontes por chave).
 * Nunca inventa NSU para import/portal/consulta.
 */
enum DocumentAcquisitionSource: string
{
    case ManualXml = 'MANUAL_XML';
    case ManualZip = 'MANUAL_ZIP';
    case AutXmlDistNsu = 'AUTXML_DIST_NSU';
    case CteDistNsu = 'CTE_DIST_NSU';
    case CteAutXmlDistNsu = 'CTE_AUTXML_DIST_NSU';
    case EmitterPush = 'EMITTER_PUSH';
    case MaOfficialPackage = 'MA_OFFICIAL_PACKAGE';
    case MaM2mRetrieval = 'MA_M2M_RETRIEVAL';
    case MaAssistedUpload = 'MA_ASSISTED_UPLOAD';
    case SvrsNfceDownloadXmlDfe = 'SVRS_NFCE_DOWNLOAD_XML_DFE';
    case SvrsNfe55DownloadXmlDfe = 'SVRS_NFE55_DOWNLOAD_XML_DFE';
    case Adn = 'ADN';
    case NfeDistDfe = 'NFE_DISTDFE';
    case ProtocolQuery = 'PROTOCOL_QUERY'; // só metadados/chave — não XML de guarda

    public function label(): string
    {
        return match ($this) {
            self::ManualXml => 'Importação XML',
            self::ManualZip => 'Importação ZIP',
            self::AutXmlDistNsu => 'DistDFe autXML NF-e (NSU)',
            self::CteDistNsu => 'DistDFe CT-e (NSU)',
            self::CteAutXmlDistNsu => 'DistDFe autXML CT-e (NSU)',
            self::EmitterPush => 'Entrega autenticada do emissor',
            self::MaOfficialPackage => 'Pacote oficial SEFAZ-MA',
            self::MaM2mRetrieval => 'Recuperação M2M MA',
            self::MaAssistedUpload => 'Upload assistido MA',
            self::SvrsNfceDownloadXmlDfe => 'Download XML NFC-e SVRS',
            self::SvrsNfe55DownloadXmlDfe => 'Download XML NF-e 55 SVRS',
            self::Adn => 'ADN NFS-e',
            self::NfeDistDfe => 'DistDFe NF-e',
            self::ProtocolQuery => 'Consulta de protocolo',
        };
    }

    public function isMaOutbound(): bool
    {
        return in_array($this, [
            self::MaOfficialPackage,
            self::MaM2mRetrieval,
            self::MaAssistedUpload,
            self::SvrsNfceDownloadXmlDfe,
            self::SvrsNfe55DownloadXmlDfe,
        ], true);
    }

    public function isManualImport(): bool
    {
        return in_array($this, [
            self::ManualXml,
            self::ManualZip,
        ], true);
    }

    public function isAutXml(): bool
    {
        return in_array($this, [
            self::AutXmlDistNsu,
            self::CteAutXmlDistNsu,
        ], true);
    }

    public function isCteChannel(): bool
    {
        return in_array($this, [
            self::CteDistNsu,
            self::CteAutXmlDistNsu,
            self::EmitterPush,
        ], true);
    }
}
