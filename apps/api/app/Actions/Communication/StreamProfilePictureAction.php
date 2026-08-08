<?php

namespace App\Actions\Communication;

use App\Enums\Communication\ProfilePictureState;
use App\Models\CommunicationInboxIdentityProfile;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\ProfilePicture\ProfilePictureMissingObjectHealer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class StreamProfilePictureAction
{
    public function __construct(
        private MediaStore $media,
        private ProfilePictureMissingObjectHealer $missingObjectHealer,
    ) {}

    public function execute(CommunicationInboxIdentityProfile $profile, int $version, ?string $ifNoneMatch): Response|StreamedResponse|null
    {
        $context = $this->context($profile, $version);
        if ($profile->profile_picture_state !== ProfilePictureState::Ready
            || (int) $profile->profile_picture_version !== $version
            || ! is_string($profile->profile_picture_object_id)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $profile->profile_picture_object_id) !== 1
            || ! is_string($profile->profile_picture_mime_type)
            || ! in_array($profile->profile_picture_mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! is_numeric($profile->profile_picture_size_bytes)
            || (int) $profile->profile_picture_size_bytes < 1
            || (int) $profile->profile_picture_size_bytes > (int) config('communication.profile_pictures.max_bytes', 2_097_152)
            || ! is_string($profile->profile_picture_sha256)
            || preg_match('/^[a-f0-9]{64}$/', $profile->profile_picture_sha256) !== 1
            || ! $this->contextMatches($profile->profile_picture_storage_context, $context)) {
            return null;
        }
        if (! $this->objectExists($profile->profile_picture_object_id)) {
            $this->missingObjectHealer->heal($profile, $version);

            return null;
        }
        $etag = '"'.$profile->profile_picture_sha256.'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache, must-revalidate', 'X-Content-Type-Options' => 'nosniff'];
        if ($this->etagMatches($ifNoneMatch, $etag)) {
            return response('', 304, $headers);
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (! is_resource($stream)) {
            return null;
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            foreach ($this->media->readChunks($profile->profile_picture_object_id, $context) as $chunk) {
                $size += strlen($chunk);
                if ($size > (int) $profile->profile_picture_size_bytes || fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Asset de foto inválido.');
                }
                hash_update($hash, $chunk);
            }
        } catch (Throwable) {
            fclose($stream);
            $this->missingObjectHealer->heal($profile, $version);

            return null;
        }
        if ($size !== (int) $profile->profile_picture_size_bytes || ! hash_equals($profile->profile_picture_sha256, hash_final($hash))) {
            fclose($stream);

            return null;
        }
        rewind($stream);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers + [
            'Content-Type' => $profile->profile_picture_mime_type,
            'Content-Length' => (string) $profile->profile_picture_size_bytes,
        ]);
    }

    /** @return array{tenant_id:int,inbox_id:int,profile_id:int,version:int,purpose:string} */
    private function context(CommunicationInboxIdentityProfile $profile, int $version): array
    {
        return [
            'tenant_id' => (int) $profile->tenant_id,
            'inbox_id' => (int) $profile->inbox_id,
            'profile_id' => (int) $profile->id,
            'version' => $version,
            'purpose' => 'COMMUNICATION_MEDIA',
        ];
    }

    private function objectExists(string $objectId): bool
    {
        try {
            return $this->media->exists($objectId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * PostgreSQL jsonb does not preserve object key order. Keep strict value and
     * key checks while making the comparison independent from that order.
     *
     * @param  array{tenant_id:int,inbox_id:int,profile_id:int,version:int,purpose:string}  $expected
     */
    private function contextMatches(mixed $stored, array $expected): bool
    {
        if (! is_array($stored)) {
            return false;
        }

        ksort($stored);
        ksort($expected);

        return $stored === $expected;
    }

    private function etagMatches(?string $header, string $etag): bool
    {
        if ($header === null) {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
