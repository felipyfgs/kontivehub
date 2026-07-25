<?php

namespace App\Services\Clients;

use App\Contracts\CnpjRegistrationLookup;
use App\Domain\Cnpj;
use App\DTO\Cnpj\CnpjRegistrationLookupResult;
use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Orquestra CNPJ.ws (primária) + Consulta CNPJ SERPRO (flag) + CCMEI MEI (flag).
 */
final class RegistrationLookupOrchestrator implements CnpjRegistrationLookup
{
    public const CACHE_PREFIX = 'cnpj-registration-lookup:merged:';

    public function __construct(
        private readonly CnpjWsRegistrationLookup $cnpjWs,
        private readonly SerproConsultaCnpjLookup $serproConsulta,
        private readonly RegistrationLookupMerger $merger,
        private readonly CcmeiRegistrationEnricher $ccmeiEnricher,
    ) {}

    public function find(string $cnpj): CnpjRegistrationLookupResult
    {
        return $this->findForClient($cnpj, null);
    }

    public function findForClient(string $cnpj, ?Client $client): CnpjRegistrationLookupResult
    {
        $normalized = Cnpj::parse($cnpj)->value();
        if (! ctype_digit($normalized)) {
            throw new RuntimeException('A consulta pública ainda aceita somente CNPJ numérico. Preencha o cadastro manualmente.');
        }

        $ttl = max((int) config('services.cnpj_public_lookup.cache_seconds', 86400), 0);
        $cacheKey = self::CACHE_PREFIX.$this->cacheSuffix($normalized);
        if ($client === null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return CnpjRegistrationLookupResult::fromArray($cached);
            }
        }

        $base = $this->cnpjWs->find($normalized);
        $result = $base;
        $primary = CnpjWsRegistrationLookup::SOURCE;

        $serpro = $this->serproConsulta->find($normalized);
        if ($serpro !== null) {
            $result = $this->merger->merge($base, $serpro, SerproConsultaCnpjLookup::SOURCE);
            $primary = SerproConsultaCnpjLookup::SOURCE;
        }

        $result = $this->ccmeiEnricher->enrich($result, $normalized, $client);

        // Recalcula source primária se CCMEI entrou nas sources
        if (in_array(SerproConsultaCnpjLookup::SOURCE, $result->sourcesUsed, true)) {
            $primary = SerproConsultaCnpjLookup::SOURCE;
        }

        $result = new CnpjRegistrationLookupResult(
            source: $primary,
            sourceUpdatedAt: $result->sourceUpdatedAt,
            client: $result->client,
            establishment: $result->establishment,
            sourcesUsed: $result->sourcesUsed,
        );

        if ($client === null) {
            Cache::put($cacheKey, $result->toArray(), $ttl);
        }

        return $result;
    }

    public function getCached(string $cnpj): ?CnpjRegistrationLookupResult
    {
        try {
            $normalized = Cnpj::parse($cnpj)->value();
        } catch (\InvalidArgumentException) {
            return null;
        }

        $cached = Cache::get(self::CACHE_PREFIX.$this->cacheSuffix($normalized));
        if (is_array($cached)) {
            return CnpjRegistrationLookupResult::fromArray($cached);
        }

        return $this->cnpjWs->getCached($normalized);
    }

    private function cacheSuffix(string $cnpj): string
    {
        $serpro = $this->serproConsulta->enabled() ? '1' : '0';
        $ccmei = $this->ccmeiEnricher->enabled() ? '1' : '0';

        return "{$cnpj}:s{$serpro}c{$ccmei}";
    }
}
