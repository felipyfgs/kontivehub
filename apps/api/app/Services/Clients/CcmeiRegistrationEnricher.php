<?php

namespace App\Services\Clients;

use App\Domain\Cnpj;
use App\DTO\Cnpj\AddressData;
use App\DTO\Cnpj\ClientRegistrationData;
use App\DTO\Cnpj\CnpjRegistrationLookupResult;
use App\DTO\Cnpj\EstablishmentRegistrationData;
use App\DTO\Cnpj\ShareholderData;
use App\Enums\RegistrationStatus;
use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Enrichment opcional via Integra Contador CCMEI / DADOSCCMEI122 (somente MEI).
 * Não substitui a consulta pública; merge fail-soft.
 */
final class CcmeiRegistrationEnricher
{
    public const SOURCE = 'CCMEI';

    public function __construct(
        private readonly RegistrationLookupMerger $merger,
        private readonly ?CcmeiDadosFetcher $fetcher = null,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.cnpj_public_lookup.ccmei_enrichment', false)
            && $this->fetcher !== null;
    }

    public function enrich(
        CnpjRegistrationLookupResult $base,
        string $cnpj,
        ?Client $client = null,
    ): CnpjRegistrationLookupResult {
        if (! $this->enabled()) {
            return $base;
        }

        if ($base->establishment->meiOptant !== true && $client === null) {
            return $base;
        }

        try {
            $payload = $this->fetcher?->fetch($cnpj, $client);
        } catch (\Throwable $exception) {
            Log::warning('ccmei_registration_enrichment.failed', [
                'message' => $exception->getMessage(),
            ]);

            return $base;
        }

        if (! is_array($payload) || $payload === []) {
            return $base;
        }

        try {
            $overlay = $this->mapPayload($cnpj, $payload);
        } catch (\Throwable $exception) {
            Log::warning('ccmei_registration_enrichment.map_failed', [
                'message' => $exception->getMessage(),
            ]);

            return $base;
        }

        return $this->merger->merge($base, $overlay, $base->source);
    }

    /**
     * @param  array<string, mixed>  $payload  dados decodificados de DADOSCCMEI122
     */
    public function mapPayload(string $cnpj, array $payload): CnpjRegistrationLookupResult
    {
        $parsed = Cnpj::parse($cnpj);
        $legalName = $this->nullableString($payload['nomeEmpresarial'] ?? $payload['nome_empresarial'] ?? null);
        if ($legalName === null) {
            throw new \RuntimeException('CCMEI sem nome empresarial.');
        }

        $endereco = is_array($payload['enderecoComercial'] ?? null)
            ? $payload['enderecoComercial']
            : (is_array($payload['endereco'] ?? null) ? $payload['endereco'] : []);
        $atividade = is_array($payload['atividade'] ?? null) ? $payload['atividade'] : [];
        $empresario = is_array($payload['empresario'] ?? null) ? $payload['empresario'] : [];

        $capital = $payload['capitalSocial'] ?? $payload['capital_social'] ?? null;
        $capitalSocial = is_numeric($capital) ? number_format((float) $capital, 2, '.', '') : null;

        $situacao = $this->nullableString($payload['situacaoCadastralVigente'] ?? $payload['situacao'] ?? null);
        $status = RegistrationStatus::fromExternal($situacao);

        $shareholders = [];
        $empresarioNome = $this->nullableString($empresario['nome'] ?? null);
        if ($empresarioNome !== null) {
            $shareholders[] = new ShareholderData(
                name: $empresarioNome,
                type: 'Empresário',
                qualificationName: 'Empresário',
                documentMasked: null,
            );
        }

        $mainCode = $this->nullableString($atividade['codigo'] ?? $atividade['cnae'] ?? null);
        $mainName = $this->nullableString($atividade['descricao'] ?? null);

        return new CnpjRegistrationLookupResult(
            source: self::SOURCE,
            sourceUpdatedAt: null,
            client: new ClientRegistrationData(
                rootCnpj: $parsed->root(),
                legalName: $legalName,
                capitalSocial: $capitalSocial,
            ),
            establishment: new EstablishmentRegistrationData(
                cnpj: $parsed->value(),
                tradeName: null,
                isMatrix: true,
                registrationStatus: $status,
                registrationStatusAt: $this->nullableString($payload['dataInicioSituacaoCadastral'] ?? null),
                registrationStatusReason: null,
                activityStartedAt: $this->nullableString($payload['dataInicioAtividades'] ?? null),
                mainCnaeCode: $mainCode,
                mainCnaeName: $mainName,
                address: new AddressData(
                    postalCode: $this->digitsOnly($endereco['cep'] ?? null),
                    street: $this->nullableString($endereco['logradouro'] ?? null),
                    number: $this->nullableString($endereco['numero'] ?? null),
                    complement: $this->nullableString($endereco['complemento'] ?? null),
                    district: $this->nullableString($endereco['bairro'] ?? null),
                    city: $this->nullableString($endereco['municipio'] ?? $endereco['cidade'] ?? null),
                    state: $this->nullableString($endereco['uf'] ?? null),
                    country: 'Brasil',
                ),
                publicEmail: null,
                publicPhone: null,
                sourceUpdatedAt: null,
                secondaryCnaes: $mainCode !== null ? [] : [],
                shareholders: $shareholders,
                meiOptant: true,
                simplesOptant: true,
            ),
            sourcesUsed: [self::SOURCE],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function digitsOnly(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
