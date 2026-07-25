<?php

namespace App\Services\Integra;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use Throwable;

/**
 * Assinatura XMLDSig Enveloped do Termo (RSA-SHA256, SHA-256, C14N, X509 final).
 * Usado pelo job A1 gerenciado e por fixtures de teste.
 */
final class TermoXmlSigner
{
    public const TRANSFORM_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public const TRANSFORM_C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /**
     * @param  string  $unsignedXml  XML canônico sem Signature
     * @param  string  $privateKeyPem  PEM da chave privada RSA
     * @param  string  $certificatePem  PEM do certificado final (somente o leaf)
     */
    public function sign(string $unsignedXml, string $privateKeyPem, string $certificatePem): string
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadXML($unsignedXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $dom->documentElement === null) {
            throw new RuntimeException('XML do Termo malformado para assinatura.');
        }

        if ($dom->documentElement->localName !== 'termoDeAutorizacao') {
            throw new RuntimeException('Raiz do Termo deve ser termoDeAutorizacao.');
        }

        try {
            $signature = new XMLSecurityDSig;
            $signature->setCanonicalMethod(XMLSecurityDSig::C14N);
            // URI="" (documento inteiro) + Enveloped + C14N — padrão oficial SERPRO.
            $signature->addReference(
                $dom,
                XMLSecurityDSig::SHA256,
                [
                    self::TRANSFORM_ENVELOPED,
                    self::TRANSFORM_C14N,
                ],
                ['force_uri' => true, 'overwrite' => false],
            );

            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
            $key->loadKey($privateKeyPem, false);
            $signature->sign($key);

            // EndCertOnly: somente certificado final (sem cadeia).
            $signature->add509Cert($certificatePem, true, false, false);
            $signature->appendSignature($dom->documentElement);

            $signed = $dom->saveXML();
            if ($signed === false || $signed === '') {
                throw new RuntimeException('Falha ao serializar Termo assinado.');
            }

            return $signed;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Falha na assinatura XMLDSig do Termo: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Assina a partir de PFX binário + senha (temporário em memória).
     */
    public function signWithPfx(string $unsignedXml, string $pfxBinary, string $password): string
    {
        $certs = [];
        $keys = [];
        if (! openssl_pkcs12_read($pfxBinary, $certs, $password)) {
            throw new RuntimeException('Não foi possível abrir o PFX para assinar o Termo.');
        }

        $privatePem = $certs['pkey'] ?? null;
        $certPem = $certs['cert'] ?? null;
        unset($certs, $keys, $pfxBinary, $password);

        if (! is_string($privatePem) || $privatePem === '' || ! is_string($certPem) || $certPem === '') {
            throw new RuntimeException('PFX sem chave/certificado utilizáveis.');
        }

        try {
            return $this->sign($unsignedXml, $privatePem, $certPem);
        } finally {
            // Best-effort wipe of sensitive strings (PHP immutability limits guarantees).
            $privatePem = str_repeat("\0", strlen($privatePem));
            $certPem = str_repeat("\0", strlen($certPem));
            unset($privatePem, $certPem);
        }
    }
}
