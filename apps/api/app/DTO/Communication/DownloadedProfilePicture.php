<?php

namespace App\DTO\Communication;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final readonly class DownloadedProfilePicture
{
    /** @param resource|StreamInterface $stream */
    public function __construct(public mixed $stream, public string $mimeType, public int $sizeBytes)
    {
        if (! is_resource($stream) && ! $stream instanceof StreamInterface) {
            throw new InvalidArgumentException('PROFILE_PICTURE_STREAM_INVALID');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);

            return;
        }
        $this->stream->close();
    }
}
