<?php

namespace App\Contracts;

use App\DTO\Communication\DownloadedProfilePicture;

interface CommunicationProfilePictureDownloader
{
    /** The URL is intentionally never persisted by callers. */
    public function download(string $url): DownloadedProfilePicture;
}
