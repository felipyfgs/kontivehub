<?php

namespace App\Services\Clients;

use App\Domain\Cnpj;
use App\DTO\Cnpj\AddressData;
use App\DTO\Cnpj\ClientRegistrationData;
use App\DTO\Cnpj\CnaeData;
use App\DTO\Cnpj\CnpjRegistrationLookupResult;
use App\DTO\Cnpj\DocumentMask;
use App\DTO\Cnpj\EstablishmentRegistrationData;
use App\DTO\Cnpj\ShareholderData;
use App\Enums\RegistrationStatus;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Adapter da API SERPRO Consulta CNPJ (produto separado do Integra Contador).
 * Usa o tier QSA (sem CPF cru de sócios). Fail-soft quando desabilitado/indisponível.
 */
final class SerproConsultaCnpjLookup
{
    public const SOURCE = 'SERPRO_CONSULTA';

    public function enabled(): bool
    {
        if (! (bool) config('services.cnpj_serpro_consulta.enabled', false)) {
            return false;
        }

        $consumerKey = (string) config('services.cnpj_serpro_consulta.consumer_key', '');
        $consumerSecret = (string) config('services.cnpj_serpro_consulta.consumer_secret', '');

        return $consumerKey !== '' && $consumerSecret !== '';
    }

    public function find(string $cnpj): ?CnpjRegistrationLookupResult
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $normalized = Cnpj::parse($cnpj)->value();
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (! ctype_digit($normalized)) {
            return null;
        }

        $ttl = max((int) config('services.cnpj_serpro_consulta.cache_seconds', 86400), 0);
        $cacheKey = 'cnpj-registration-lookup:serpro:'.$normalized;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return CnpjRegistrationLookupResult::fromArray($cached);
        }

        try {
            $result = $this->request($normalized);
        } catch (RuntimeException $exception) {
            Log::warning('serpro_consulta_cnpj.failed', [
                'cnpj_root' => substr($normalized, 0, 8),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        Cache::put($cacheKey, $result->toArray(), $ttl);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapPayload(string $cnpj, array $payload): CnpjRegistrationLookupResult
    {
        $legalName = $this->nullableString($payload['nomeEmpresarial'] ?? $payload['nome_empresarial'] ?? null);
        if ($legalName === null) {
            throw new RuntimeException('Consulta CNPJ SERPRO retornou dados incompletos.');
        }

        $parsed = Cnpj::parse($cnpj);
        $natureza = is_array($payload['naturezaJuridica'] ?? null)
            ? $payload['naturezaJuridica']
            : (is_array($payload['natureza_juridica'] ?? null) ? $payload['natureza_juridica'] : []);
        $situacao = is_array($payload['situacaoCadastral'] ?? null)
            ? $payload['situacaoCadastral']
            : (is_array($payload['situacao_cadastral'] ?? null) ? $payload['situacao_cadastral'] : []);
        $cnae = is_array($payload['cnaePrincipal'] ?? null)
            ? $payload['cnaePrincipal']
            : (is_array($payload['cnae_principal'] ?? null) ? $payload['cnae_principal'] : []);
        $endereco = is_array($payload['endereco'] ?? null) ? $payload['endereco'] : [];
        $infoExtra = is_array($payload['informacoesAdicionais'] ?? null)
            ? $payload['informacoesAdicionais']
            : (is_array($payload['informacoes_adicionais'] ?? null) ? $payload['informacoes_adicionais'] : []);

        $tipoEst = (string) ($payload['tipoEstabelecimento'] ?? $payload['tipo_estabelecimento'] ?? '1');
        $isMatrix = in_array($tipoEst, ['1', 'MATRIZ', 'M'], true);

        $porte = $this->nullableString($payload['porteEmpresa'] ?? $payload['porte'] ?? null);
        $capital = $payload['capitalSocial'] ?? $payload['capital_social'] ?? null;
        $capitalSocial = is_numeric($capital) ? number_format((float) $capital, 2, '.', '') : null;

        $statusCode = $this->nullableString($situacao['codigo'] ?? $situacao['code'] ?? null);
        $status = RegistrationStatus::fromExternal(
            $this->nullableString($situacao['motivo'] ?? null) ?? $statusCode
        );

        $phones = $payload['telefones'] ?? [];
        $publicPhone = null;
        $publicPhoneSecondary = null;
        if (is_array($phones)) {
            if (isset($phones[0]) && is_array($phones[0])) {
                $publicPhone = $this->composePhone($phones[0]['ddd'] ?? null, $phones[0]['numero'] ?? null);
            }
            if (isset($phones[1]) && is_array($phones[1])) {
                $publicPhoneSecondary = $this->composePhone($phones[1]['ddd'] ?? null, $phones[1]['numero'] ?? null);
            }
        }

        $secondary = [];
        foreach ($payload['cnaeSecundarias'] ?? $payload['cnaes_secundarios'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = CnaeData::fromArray([
                'code' => $row['codigo'] ?? $row['code'] ?? null,
                'name' => $row['descricao'] ?? $row['name'] ?? null,
            ]);
            if ($mapped !== null) {
                $secondary[] = $mapped;
            }
        }

        return new CnpjRegistrationLookupResult(
            source: self::SOURCE,
            sourceUpdatedAt: null,
            client: new ClientRegistrationData(
                rootCnpj: $parsed->root(),
                legalName: $legalName,
                legalNatureCode: $this->nullableString($natureza['codigo'] ?? $natureza['code'] ?? null),
                legalNatureName: $this->nullableString($natureza['descricao'] ?? $natureza['description'] ?? null),
                companySizeCode: $porte,
                companySizeName: $this->porteLabel($porte),
                capitalSocial: $capitalSocial,
            ),
            establishment: new EstablishmentRegistrationData(
                cnpj: $parsed->value(),
                tradeName: $this->nullableString($payload['nomeFantasia'] ?? $payload['nome_fantasia'] ?? null),
                isMatrix: $isMatrix,
                registrationStatus: $status,
                registrationStatusAt: $this->dateOnly($situacao['data'] ?? null),
                registrationStatusReason: $this->nullableString($situacao['motivo'] ?? null),
                activityStartedAt: $this->dateOnly($payload['dataAbertura'] ?? $payload['data_abertura'] ?? null),
                mainCnaeCode: $this->nullableString($cnae['codigo'] ?? $cnae['code'] ?? null),
                mainCnaeName: $this->nullableString($cnae['descricao'] ?? $cnae['description'] ?? null),
                address: new AddressData(
                    postalCode: $this->digitsOnly($endereco['cep'] ?? null),
                    streetType: null,
                    street: $this->nullableString($endereco['logradouro'] ?? null),
                    number: $this->nullableString($endereco['numero'] ?? null),
                    complement: $this->nullableString($endereco['complemento'] ?? null),
                    district: $this->nullableString($endereco['bairro'] ?? null),
                    city: $this->nullableString($endereco['municipio'] ?? $endereco['cidade'] ?? null),
                    cityIbgeCode: $this->nullableString($endereco['codigoMunicipio'] ?? $endereco['codigo_municipio'] ?? null),
                    state: $this->nullableString($endereco['uf'] ?? null),
                    country: 'Brasil',
                ),
                publicEmail: $this->nullableString($payload['correioEletronico'] ?? $payload['correio_eletronico'] ?? null),
                publicPhone: $publicPhone,
                sourceUpdatedAt: null,
                secondaryCnaes: $secondary,
                stateRegistrations: [],
                shareholders: $this->mapShareholders($payload['socios'] ?? []),
                publicPhoneSecondary: $publicPhoneSecondary,
                publicFax: null,
                specialSituation: $this->nullableString($payload['situacaoEspecial'] ?? $payload['situacao_especial'] ?? null),
                specialSituationAt: null,
                simplesOptant: $this->nullableBool($infoExtra['optanteSimples'] ?? $infoExtra['optante_simples'] ?? null),
                meiOptant: $this->nullableBool($infoExtra['optanteMei'] ?? $infoExtra['optante_mei'] ?? null),
            ),
            sourcesUsed: [self::SOURCE],
        );
    }

    private function request(string $cnpj): CnpjRegistrationLookupResult
    {
        $token = $this->accessToken();
        $baseUrl = rtrim((string) config('services.cnpj_serpro_consulta.base_url'), '/');
        $path = (string) config('services.cnpj_serpro_consulta.qsa_path', '/v1/qsa');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(max((int) config('services.cnpj_serpro_consulta.timeout_seconds', 8), 1))
                ->get(rtrim($baseUrl.$path, '/').'/'.$cnpj);
        } catch (ConnectionException) {
            throw new RuntimeException('Consulta CNPJ SERPRO indisponível.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException('CNPJ não localizado na Consulta CNPJ SERPRO.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Consulta CNPJ SERPRO indisponível.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return $this->mapPayload($cnpj, $payload);
    }

    private function accessToken(): string
    {
        $cacheKey = 'cnpj-serpro-consulta:oauth-token';
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUrl = (string) config('services.cnpj_serpro_consulta.token_url');
        $consumerKey = (string) config('services.cnpj_serpro_consulta.consumer_key');
        $consumerSecret = (string) config('services.cnpj_serpro_consulta.consumer_secret');

        try {
            $response = Http::asForm()
                ->withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(max((int) config('services.cnpj_serpro_consulta.timeout_seconds', 8), 1))
                ->post($tokenUrl, ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new RuntimeException('OAuth Consulta CNPJ SERPRO indisponível.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('OAuth Consulta CNPJ SERPRO falhou.');
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('OAuth Consulta CNPJ SERPRO sem access_token.');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($cacheKey, $token, max($expiresIn - 60, 60));

        return $token;
    }

    /**
     * @return list<ShareholderData>
     */
    private function mapShareholders(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->nullableString($row['nome'] ?? $row['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $out[] = new ShareholderData(
                name: $name,
                type: $this->nullableString($row['tipoSocio'] ?? $row['tipo'] ?? null),
                qualificationCode: null,
                qualificationName: $this->nullableString($row['qualificacao'] ?? null),
                enteredAt: $this->dateOnly($row['dataInclusao'] ?? $row['data_inclusao'] ?? null),
                // Tier QSA não deve trazer CPF; mascarar se vier por engano.
                documentMasked: DocumentMask::ensureMasked($row['cpf'] ?? $row['cnpj'] ?? null),
            );
        }

        return $out;
    }

    private function porteLabel(?string $code): ?string
    {
        return match ($code) {
            '01' => 'Microempresa (ME)',
            '03' => 'Empresa de pequeno porte (EPP)',
            '05' => 'Demais',
            default => null,
        };
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

    private function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
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
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
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
