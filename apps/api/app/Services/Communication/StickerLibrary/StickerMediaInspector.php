<?php

namespace App\Services\Communication\StickerLibrary;

use App\DTO\Communication\StickerMediaData;
use Illuminate\Validation\ValidationException;

final class StickerMediaInspector
{
    public function inspect(string $path, ?string $clientMime = null, ?string $expectedDigest = null): StickerMediaData
    {
        $size = filesize($path);
        $maximum = max(1, (int) config('communication.sticker_library.max_item_bytes', 1_048_576));
        if (! is_int($size) || $size < 1 || $size > $maximum) {
            $this->invalid('O WebP está vazio ou excede o limite da biblioteca.');
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)
            || strlen($bytes) < 16
            || substr($bytes, 0, 4) !== 'RIFF'
            || substr($bytes, 8, 4) !== 'WEBP') {
            $this->invalid('A assinatura do arquivo não é WebP.');
        }
        if ($clientMime !== null && strtolower(trim(explode(';', $clientMime, 2)[0])) !== 'image/webp') {
            $this->invalid('O MIME informado deve ser image/webp.');
        }

        $dimensions = @getimagesize($path);
        if (! is_array($dimensions) || ($dimensions['mime'] ?? null) !== 'image/webp') {
            $this->invalid('Não foi possível validar as dimensões do WebP.');
        }
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        $maximumDimension = max(1, (int) config('communication.sticker_library.max_dimension', 512));
        if ($width < 1 || $height < 1 || $width > $maximumDimension || $height > $maximumDimension) {
            $this->invalid("As dimensões do WebP devem estar entre 1 e {$maximumDimension}px.");
        }

        $animated = str_contains($bytes, 'ANIM');
        if ($animated && ! (bool) config('communication.sticker_library.allow_animated', true)) {
            $this->invalid('Figurinhas animadas estão desabilitadas.');
        }

        $digest = hash('sha256', $bytes);
        if ($expectedDigest !== null && ! hash_equals(strtolower($expectedDigest), $digest)) {
            $this->invalid('O digest do WebP não corresponde ao esperado.');
        }

        return new StickerMediaData($size, $digest, $width, $height, $animated);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
