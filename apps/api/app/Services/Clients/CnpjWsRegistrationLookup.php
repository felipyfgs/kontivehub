<?php

namespace App\Services\Clients;

use App\Contracts\CnpjRegistrationLookup;
use App\Domain\Cnpj;
use App\DTO\Cnpj\AddressData;
use App\DTO\Cnpj\ClientRegistrationData;
use App\DTO\Cnpj\CnaeData;
use App\DTO\Cnpj\CnpjRegistrationLookupResult;
use App\DTO\Cnpj\DocumentMask;
use App\DTO\Cnpj\EstablishmentRegistrationData;
use App\DTO\Cnpj\ShareholderData;
use App\DTO\Cnpj\StateRegistrationData;
use App\Enums\RegistrationStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Adaptador CNPJ.ws: allowlist ampliada do Cartão CNPJ; QSA só com documento mascarado.
 */
final class CnpjWsRegistrationLookup implements CnpjRegistrationLookup
{
    public const CACHE_PREFIX = 'cnpj-registration-lookup:ws:';

    public const SOURCE = 'CNPJ_WS';

    public function find(string $cnpj): CnpjRegistrationLookupResult
    {
        $normalized = Cnpj::parse($cnpj)->value();
        if (! ctype_digit($normalized)) {
            throw new RuntimeException('A consulta pública ainda aceita somente CNPJ numérico. Preencha o cadastro manualmente.');
        }

        $ttl = max((int) config('services.cnpj_public_lookup.cache_seconds', 86400), 0);
        $cacheKey = self::CACHE_PREFIX.$normalized;

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return CnpjRegistrationLookupResult::fromArray($cached);
        }

        $result = $this->request($normalized);
        Cache::put($cacheKey, $result->toArray(), $ttl);

        return $result;
    }

    public function getCached(string $cnpj): ?CnpjRegistrationLookupResult
    {
        try {
            $normalized = Cnpj::parse($cnpj)->value();
        } catch (\InvalidArgumentException) {
            return null;
        }

        $cached = Cache::get(self::CACHE_PREFIX.$normalized);
        if (! is_array($cached)) {
            return null;
        }

        return CnpjRegistrationLookupResult::fromArray($cached);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapPayload(string $cnpj, array $payload): CnpjRegistrationLookupResult
    {
        $legalName = $this->nullableString($payload['razao_social'] ?? null);
        if ($legalName === null) {
            throw new RuntimeException('A consulta pública retornou dados incompletos para este CNPJ.');
        }

        $establishment = is_array($payload['estabelecimento'] ?? null) ? $payload['estabelecimento'] : [];
        $natureza = is_array($payload['natureza_juridica'] ?? null) ? $payload['natureza_juridica'] : [];
        $porte = is_array($payload['porte'] ?? null) ? $payload['porte'] : [];
        $qualificacao = is_array($payload['qualificacao_do_responsavel'] ?? null)
            ? $payload['qualificacao_do_responsavel']
            : [];
        $atividade = is_array($establishment['atividade_principal'] ?? null) ? $establishment['atividade_principal'] : [];
        $cidade = is_array($establishment['cidade'] ?? null) ? $establishment['cidade'] : [];
        $estado = is_array($establishment['estado'] ?? null) ? $establishment['estado'] : [];
        $pais = is_array($establishment['pais'] ?? null) ? $establishment['pais'] : [];
        $simples = is_array($payload['simples'] ?? null) ? $payload['simples'] : [];
        $tipoLogradouro = $this->nullableString($establishment['tipo_logradouro'] ?? null);
        $situacaoRaw = $this->nullableString($establishment['situacao_cadastral'] ?? null);
        $status = RegistrationStatus::fromExternal($situacaoRaw);

        $sourceUpdatedAt = $this->nullableString(
            $establishment['atualizado_em']
                ?? $payload['atualizado_em']
                ?? $payload['updated_at']
                ?? null,
        );

        $parsed = Cnpj::parse($cnpj);

        $tipo = mb_strtoupper((string) ($establishment['tipo'] ?? ''));
        $isMatrix = in_array($tipo, ['MATRIZ', 'M', '1', 'S', 'TRUE'], true)
            || (($establishment['matriz'] ?? null) === true);

        $capital = $payload['capital_social'] ?? null;
        $capitalSocial = null;
        if (is_numeric($capital)) {
            $capitalSocial = number_format((float) $capital, 2, '.', '');
        }

        return new CnpjRegistrationLookupResult(
            source: self::SOURCE,
            sourceUpdatedAt: $sourceUpdatedAt,
            client: new ClientRegistrationData(
                rootCnpj: $parsed->root(),
                legalName: $legalName,
                legalNatureCode: $this->nullableString($natureza['id'] ?? $natureza['codigo'] ?? null),
                legalNatureName: $this->nullableString($natureza['descricao'] ?? null),
                companySizeCode: $this->nullableString($porte['id'] ?? $porte['codigo'] ?? null),
                companySizeName: $this->nullableString($porte['descricao'] ?? null),
                capitalSocial: $capitalSocial,
                responsibleQualificationCode: $this->nullableString($qualificacao['id'] ?? $qualificacao['codigo'] ?? null),
                responsibleQualificationName: $this->nullableString($qualificacao['descricao'] ?? null),
            ),
            establishment: new EstablishmentRegistrationData(
                cnpj: $parsed->value(),
                tradeName: $this->nullableString($establishment['nome_fantasia'] ?? null),
                isMatrix: $isMatrix,
                registrationStatus: $status,
                registrationStatusAt: $this->dateOnly($establishment['data_situacao_cadastral'] ?? null),
                registrationStatusReason: $this->nullableString($establishment['motivo_situacao_cadastral'] ?? null),
                activityStartedAt: $this->dateOnly($establishment['data_inicio_atividade'] ?? null),
                mainCnaeCode: $this->nullableString($atividade['id'] ?? $atividade['codigo'] ?? null),
                mainCnaeName: $this->nullableString($atividade['descricao'] ?? null),
                address: new AddressData(
                    postalCode: $this->digitsOnly($establishment['cep'] ?? null),
                    streetType: $tipoLogradouro,
                    street: $this->nullableString($establishment['logradouro'] ?? null),
                    number: $this->nullableString($establishment['numero'] ?? null),
                    complement: $this->nullableString($establishment['complemento'] ?? null),
                    district: $this->nullableString($establishment['bairro'] ?? null),
                    city: $this->nullableString($cidade['nome'] ?? $establishment['municipio'] ?? null),
                    cityIbgeCode: $this->nullableString($cidade['ibge_id'] ?? $cidade['codigo_ibge'] ?? null),
                    state: $this->nullableString($estado['sigla'] ?? $establishment['uf'] ?? null),
                    country: $this->nullableString($pais['nome'] ?? $establishment['pais'] ?? 'Brasil'),
                ),
                publicEmail: $this->nullableString($establishment['email'] ?? null),
                publicPhone: $this->composePhone(
                    $establishment['ddd1'] ?? $establishment['ddd'] ?? null,
                    $establishment['telefone1'] ?? $establishment['telefone'] ?? null,
                ),
                sourceUpdatedAt: $sourceUpdatedAt,
                secondaryCnaes: $this->mapSecondaryCnaes($establishment['atividades_secundarias'] ?? []),
                stateRegistrations: $this->mapStateRegistrations($establishment['inscricoes_estaduais'] ?? []),
                shareholders: $this->mapShareholders($payload['socios'] ?? []),
                publicPhoneSecondary: $this->composePhone(
                    $establishment['ddd2'] ?? null,
                    $establishment['telefone2'] ?? null,
                ),
                publicFax: $this->composePhone(
                    $establishment['ddd_fax'] ?? null,
                    $establishment['fax'] ?? null,
                ),
                specialSituation: $this->nullableString($establishment['situacao_especial'] ?? null),
                specialSituationAt: $this->dateOnly($establishment['data_situacao_especial'] ?? null),
                simplesOptant: $this->yesNoToBool($simples['simples'] ?? $simples['optante_simples'] ?? null),
                meiOptant: $this->yesNoToBool($simples['mei'] ?? $simples['optante_mei'] ?? null),
            ),
            sourcesUsed: [self::SOURCE],
        );
    }

    private function request(string $cnpj): CnpjRegistrationLookupResult
    {
        $baseUrl = rtrim((string) config('services.cnpj_public_lookup.url'), '/');

        try {
            $response = RateLimiter::attempt(
                'cnpj-ws-public-api',
                3,
                fn () => Http::acceptJson()
                    ->timeout(max((int) config('services.cnpj_public_lookup.timeout_seconds', 5), 1))
                    ->get("{$baseUrl}/{$cnpj}"),
                60,
            );
        } catch (ConnectionException) {
            throw new RuntimeException('A consulta pública de CNPJ está indisponível no momento.');
        }

        if ($response === false) {
            throw new RuntimeException('O limite da consulta pública foi atingido. Aguarde um minuto ou preencha o cadastro manualmente.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException('CNPJ não localizado na consulta pública.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('A consulta pública de CNPJ está indisponível no momento.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return $this->mapPayload($cnpj, $payload);
    }

    /**
     * @return list<CnaeData>
     */
    private function mapSecondaryCnaes(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = CnaeData::fromArray([
                'code' => $row['id'] ?? $row['codigo'] ?? null,
                'name' => $row['descricao'] ?? null,
            ]);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @return list<StateRegistrationData>
     */
    private function mapStateRegistrations(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = StateRegistrationData::fromArray($row);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @return list<ShareholderData>
     */
    private function mapShareholders(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        /** @var array<string, ShareholderData> $byKey */
        $byKey = [];
        /** @var array<string, bool> $preferSourceMasked */
        $preferSourceMasked = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->nullableString($row['nome'] ?? null);
            if ($name === null) {
                continue;
            }
            $qual = is_array($row['qualificacao_socio'] ?? null) ? $row['qualificacao_socio'] : [];
            $qualificationCode = $this->nullableString($qual['id'] ?? $qual['codigo'] ?? null);
            $enteredAt = $this->dateOnly($row['data_entrada'] ?? null);
            $rawDocument = $row['cpf_cnpj_socio'] ?? $row['cpf'] ?? $row['cnpj'] ?? null;
            $sourceAlreadyMasked = is_string($rawDocument) && str_contains($rawDocument, '*');
            $key = mb_strtolower($name).'|'.($enteredAt ?? '').'|'.($qualificationCode ?? '');

            $candidate = new ShareholderData(
                name: $name,
                type: $this->nullableString($row['tipo'] ?? null),
                qualificationCode: $qualificationCode,
                qualificationName: $this->nullableString($qual['descricao'] ?? null),
                enteredAt: $enteredAt,
                documentMasked: DocumentMask::ensureMasked($rawDocument),
            );

            if (! isset($byKey[$key])) {
                $byKey[$key] = $candidate;
                $preferSourceMasked[$key] = $sourceAlreadyMasked;

                continue;
            }

            // Preferir a entrada cujo documento já vinha mascarado pela fonte.
            if ($sourceAlreadyMasked && ! ($preferSourceMasked[$key] ?? false)) {
                $byKey[$key] = $candidate;
                $preferSourceMasked[$key] = true;
            }
        }

        return array_values($byKey);
    }

    private function composePhone(mixed $ddd, mixed $phone): ?string
    {
        $dddNorm = $this->digitsOnly($ddd);
        $phoneNorm = $this->digitsOnly($phone);
        if ($dddNorm !== null && $phoneNorm !== null) {
            return $dddNorm.$phoneNorm;
        }

        return $phoneNorm;
    }

    private function yesNoToBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        $normalized = mb_strtolower($raw);
        if (in_array($normalized, ['sim', 's', 'yes', 'true', '1'], true)) {
            return true;
        }
        if (in_array($normalized, ['nao', 'não', 'n', 'no', 'false', '0'], true)) {
            return false;
        }

        return null;
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

    private function dateOnly(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
            return $m[1];
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
