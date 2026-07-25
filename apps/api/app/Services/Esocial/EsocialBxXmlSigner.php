<?php

namespace App\Services\Esocial;

use App\Exceptions\EsocialBxException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use Throwable;

final class EsocialBxXmlSigner
{
    public function sign(string $xml, string $pfxBinary, string $password): string
    {
        try {
            $certificate = Certificate::readPfx($pfxBinary, $password);
            $signed = Signer::sign(
                $certificate,
                $xml,
                'eSocial',
                '',
                OPENSSL_ALGO_SHA256,
                [false, false, null, null],
            );

            return preg_replace('/^<\?xml[^>]+>\s*/', '', $signed) ?? $signed;
        } catch (Throwable $e) {
            throw new EsocialBxException(
                'ESOCIAL_BX_SIGNATURE_FAILED',
                'Não foi possível assinar a solicitação eSocial BX.',
                blocked: true,
                previous: $e,
            );
        }
    }
}
