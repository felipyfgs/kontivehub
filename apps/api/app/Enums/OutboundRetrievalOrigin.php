<?php

namespace App\Enums;

/**
 * Origem da solicitação de recuperação de XML de saída MA.
 */
enum OutboundRetrievalOrigin: string
{
    case MaOfficialPackage = 'MA_OFFICIAL_PACKAGE';
    case MaM2m = 'MA_M2M';
    case MaAssistedUpload = 'MA_ASSISTED_UPLOAD';
    case SvrsPortalByKey = 'SVRS_PORTAL_BY_KEY';

    public function label(): string
    {
        return match ($this) {
            self::MaOfficialPackage => 'Pacote oficial SEFAZ-MA',
            self::MaM2m => 'M2M SEFAZ-MA',
            self::MaAssistedUpload => 'Upload assistido',
            self::SvrsPortalByKey => 'Portal SVRS por chave (NFC-e)',
        };
    }

    public function isSvrs(): bool
    {
        return $this === self::SvrsPortalByKey;
    }
}
