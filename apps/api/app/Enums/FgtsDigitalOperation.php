<?php

namespace App\Enums;

enum FgtsDigitalOperation: string
{
    case Readiness = 'READINESS';
    case Authenticate = 'AUTHENTICATE';
    case QueryGuides = 'QUERY_GUIDES';
    case QueryPayment = 'QUERY_PAYMENT';
    case Preview = 'PREVIEW';
    case EmitGuide = 'EMIT_GUIDE';
    case DownloadGuide = 'DOWNLOAD_GUIDE';

    public function mutatesPortal(): bool
    {
        return $this === self::EmitGuide;
    }
}
