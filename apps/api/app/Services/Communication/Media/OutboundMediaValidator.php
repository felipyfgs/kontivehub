<?php

namespace App\Services\Communication\Media;

use App\DTO\Communication\MessageUploadData;
use App\DTO\Communication\ValidatedOutboundMediaData;
use App\Enums\Communication\MessageKind;
use Illuminate\Validation\ValidationException;

final class OutboundMediaValidator
{
    public function inspect(
        MessageUploadData $upload,
        ?MessageKind $requestedKind,
    ): ValidatedOutboundMediaData {
        $size = filesize($upload->path);
        $maximum = max(1, (int) config('communication.media.max_bytes', 20_971_520));
        if (! is_int($size) || $size < 1 || $size > $maximum) {
            throw ValidationException::withMessages([
                'file' => 'O arquivo está vazio ou excede o limite anunciado.',
            ]);
        }

        $prefix = file_get_contents($upload->path, false, null, 0, 32);
        if (! is_string($prefix)) {
            throw ValidationException::withMessages(['file' => 'Não foi possível inspecionar o arquivo.']);
        }
        $mime = $this->signatureMime($prefix, $upload->path, $requestedKind);
        $clientMime = $this->normalizeMime($upload->clientMime);
        if ($mime === null || ! $this->clientMimeMatches($mime, $clientMime)) {
            throw ValidationException::withMessages([
                'file' => 'A assinatura do arquivo não corresponde ao MIME informado.',
            ]);
        }

        $digest = hash_file('sha256', $upload->path);
        if (! is_string($digest)) {
            throw ValidationException::withMessages(['file' => 'Não foi possível calcular o digest do arquivo.']);
        }

        return new ValidatedOutboundMediaData($mime, $size, $digest);
    }

    private function signatureMime(string $prefix, string $path, ?MessageKind $requestedKind): ?string
    {
        if (str_starts_with($prefix, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($prefix, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($prefix, 'OggS')) {
            return 'audio/ogg';
        }
        if (str_starts_with($prefix, 'ID3')
            || (strlen($prefix) >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xE0) === 0xE0)) {
            return 'audio/mpeg';
        }
        if (strlen($prefix) >= 8 && substr($prefix, 4, 4) === 'ftyp') {
            return $requestedKind === MessageKind::Audio ? 'audio/mp4' : 'video/mp4';
        }
        if (str_starts_with($prefix, "\x1A\x45\xDF\xA3")) {
            return $requestedKind === MessageKind::Audio ? 'audio/webm' : 'video/webm';
        }
        if (str_starts_with($prefix, '%PDF-')) {
            return 'application/pdf';
        }
        if (in_array(substr($prefix, 0, 4), ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            return 'application/zip';
        }
        $content = file_get_contents($path);
        if (! str_contains($prefix, "\0") && is_string($content) && mb_check_encoding($content, 'UTF-8')) {
            return 'text/plain';
        }

        return null;
    }

    private function clientMimeMatches(string $signatureMime, string $clientMime): bool
    {
        return $signatureMime === $clientMime
            || (in_array($signatureMime, ['audio/mp4', 'video/mp4'], true) && $clientMime === 'application/mp4')
            || ($signatureMime === 'audio/mp4' && $clientMime === 'video/mp4')
            || ($signatureMime === 'audio/webm' && $clientMime === 'video/webm');
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) === 1
            ? $mime
            : 'application/octet-stream';
    }
}
