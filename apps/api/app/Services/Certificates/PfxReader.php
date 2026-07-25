<?php

namespace App\Services\Certificates;

use App\Contracts\PfxReaderInterface;
use App\Domain\Cnpj;
use Carbon\CarbonImmutable;
use NFePHP\Common\Certificate;
use RuntimeException;
use Throwable;

/**
 * Leitura de PFX via sped-common — somente metadados; sem persistir PEM.
 */
final class PfxReader implements PfxReaderInterface
{
    /**
     * @return array{
     *   pfx: string,
     *   password: string,
     *   subject_name: string,
     *   cnpj: string,
     *   fingerprint_sha256: string,
     *   valid_from: CarbonImmutable,
     *   valid_to: CarbonImmutable
     * }
     */
    public function read(string $pfxBinary, string $password): array
    {
        try {
            $cert = Certificate::readPfx($pfxBinary, $password);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível abrir o PFX com a senha informada.', 0, $e);
        }

        if ($cert->isExpired()) {
            throw new RuntimeException('Certificado expirado.');
        }

        $cnpjRaw = (string) $cert->getCnpj();
        $cnpj = Cnpj::tryParse($cnpjRaw);
        if ($cnpj === null) {
            $company = (string) $cert->getCompanyName();
            if (preg_match('/[0-9A-Z]{14}/i', $company, $m)) {
                $cnpj = Cnpj::tryParse($m[0]);
            }
        }

        if ($cnpj === null) {
            throw new RuntimeException('CNPJ do titular não encontrado ou inválido no certificado.');
        }

        $validFrom = CarbonImmutable::instance($cert->getValidFrom());
        $validTo = CarbonImmutable::instance($cert->getValidTo());
        $fingerprint = strtoupper(hash('sha256', (string) $cert));

        return [
            'pfx' => $pfxBinary,
            'password' => $password,
            'subject_name' => (string) $cert->getCompanyName(),
            'cnpj' => $cnpj->value(),
            'fingerprint_sha256' => $fingerprint,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ];
    }
}
