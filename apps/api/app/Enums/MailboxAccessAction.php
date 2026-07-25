<?php

namespace App\Enums;

enum MailboxAccessAction: string
{
    case View = 'VIEW';
    case DownloadBody = 'DOWNLOAD_BODY';
    case DownloadAttachment = 'DOWNLOAD_ATTACHMENT';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Visualização',
            self::DownloadBody => 'Download do corpo',
            self::DownloadAttachment => 'Download de anexo',
        };
    }
}
