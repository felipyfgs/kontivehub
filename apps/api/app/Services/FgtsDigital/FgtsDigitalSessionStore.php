<?php

namespace App\Services\FgtsDigital;

use App\Contracts\SecureObjectStore;
use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalSessionStatus;
use App\Enums\SecureObjectPurpose;
use App\Models\Client;
use App\Models\FgtsDigitalSession;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FgtsDigitalSessionStore
{
    public function __construct(private readonly SecureObjectStore $vault) {}

    /** @param array<string, mixed> $storageState */
    public function store(
        int $tenantId,
        int $clientId,
        FgtsDigitalCredentialSource $source,
        string $fingerprint,
        string $profileType,
        string $targetIdentifierHash,
        array $storageState,
        ?int $representationId = null,
        ?CarbonImmutable $expiresAt = null,
    ): FgtsDigitalSession {
        $this->validateState($storageState);
        $expiresAt ??= CarbonImmutable::now()->addMinutes((int) config('fgts_digital.session.ttl_minutes', 30));
        $json = json_encode(
            $storageState,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $objectId = null;
        try {
            return DB::transaction(function () use (
                $tenantId,
                $clientId,
                $representationId,
                $source,
                $fingerprint,
                $profileType,
                $targetIdentifierHash,
                $expiresAt,
                $json,
                &$objectId,
            ): FgtsDigitalSession {
                $client = Client::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($clientId)
                    ->lockForUpdate()
                    ->first(['id']);
                if ($client === null) {
                    throw new FgtsDigitalException(
                        'Cliente não encontrado.',
                        'FGTS_DIGITAL_CLIENT_NOT_FOUND',
                        404,
                    );
                }

                $session = FgtsDigitalSession::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $clientId,
                    'representation_id' => $representationId,
                    'credential_source' => $source,
                    'credential_fingerprint' => $fingerprint,
                    'profile_type' => $profileType,
                    'target_identifier_hash' => $targetIdentifierHash,
                    'contract_version' => (string) config(
                        'fgts_digital.contract_version',
                        1,
                    ),
                    'status' => FgtsDigitalSessionStatus::Ready,
                    'expires_at' => $expiresAt,
                ]);

                try {
                    $objectId = $this->vault->put(
                        $json,
                        self::aad($session),
                    );
                } catch (Throwable) {
                    throw new FgtsDigitalException(
                        'Não foi possível proteger a sessão importada.',
                        'FGTS_DIGITAL_SESSION_STORE_FAILED',
                        500,
                    );
                }
                $session->forceFill([
                    'vault_object_id' => $objectId,
                ])->save();

                FgtsDigitalSession::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('client_id', $clientId)
                    ->whereKeyNot($session->id)
                    ->where(
                        'status',
                        FgtsDigitalSessionStatus::Ready->value,
                    )
                    ->update([
                        'status' => FgtsDigitalSessionStatus::Revoked->value,
                    ]);

                return $session;
            });
        } catch (Throwable $exception) {
            if ($objectId !== null) {
                try {
                    $this->vault->delete($objectId);
                } catch (Throwable) {
                    // A limpeza do cofre é best effort; o erro original prevalece.
                }
            }

            throw $exception;
        }
    }

    public function latest(
        int $tenantId,
        int $clientId,
        string $fingerprint,
        string $profileType,
        string $targetIdentifierHash,
    ): ?FgtsDigitalSession {
        $session = FgtsDigitalSession::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->where('credential_fingerprint', $fingerprint)
            ->where('profile_type', $profileType)
            ->where('target_identifier_hash', $targetIdentifierHash)
            ->where('status', FgtsDigitalSessionStatus::Ready->value)
            ->orderByDesc('id')
            ->first();
        if ($session !== null && ! $session->isUsable()) {
            $session->forceFill(['status' => FgtsDigitalSessionStatus::Expired])->save();

            return null;
        }

        return $session;
    }

    /** @return array<string, mixed>|null */
    public function load(?FgtsDigitalSession $session): ?array
    {
        if ($session === null || ! $session->isUsable()) {
            return null;
        }
        $json = $this->vault->get((string) $session->vault_object_id, self::aad($session));
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->validateState($state);
        $session->forceFill(['last_used_at' => now()])->save();

        return $state;
    }

    /** @return array<string, scalar|null> */
    private static function aad(FgtsDigitalSession $session): array
    {
        return SecureObjectPurpose::FgtsDigitalSession->aadBase([
            'tenant_id' => (int) $session->tenant_id,
            'client_id' => (int) $session->client_id,
            'session_id' => (int) $session->id,
            'credential_fingerprint' => (string) $session->credential_fingerprint,
            'profile_type' => (string) $session->profile_type,
            'target_identifier_hash' => (string) $session->target_identifier_hash,
        ]);
    }

    /** @param array<string, mixed> $state */
    private function validateState(array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > (int) config('fgts_digital.session.max_import_bytes', 1_048_576)) {
            throw new FgtsDigitalException('Sessão acima do limite permitido.', 'FGTS_DIGITAL_SESSION_TOO_LARGE', 413);
        }
        if (! isset($state['cookies']) || ! is_array($state['cookies'])
            || ! isset($state['origins']) || ! is_array($state['origins'])) {
            throw new FgtsDigitalException('Storage state inválido.', 'FGTS_DIGITAL_SESSION_INVALID', 422);
        }
        $suffixes = (array) config('fgts_digital.portal.allowed_host_suffixes', []);
        foreach ($state['origins'] as $origin) {
            $host = is_array($origin) ? parse_url((string) ($origin['origin'] ?? ''), PHP_URL_HOST) : null;
            if ($host !== null && ! $this->hostAllowed((string) $host, $suffixes)) {
                throw new FgtsDigitalException('Sessão contém origem fora da allowlist.', 'FGTS_DIGITAL_SESSION_ORIGIN_BLOCKED', 422);
            }
        }
        foreach ($state['cookies'] as $cookie) {
            $domain = is_array($cookie) ? ltrim((string) ($cookie['domain'] ?? ''), '.') : '';
            if ($domain === '' || ! $this->hostAllowed($domain, $suffixes)) {
                throw new FgtsDigitalException('Sessão contém cookie fora da allowlist.', 'FGTS_DIGITAL_SESSION_ORIGIN_BLOCKED', 422);
            }
        }
    }

    /** @param list<string> $suffixes */
    private function hostAllowed(string $host, array $suffixes): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($suffixes as $suffix) {
            $suffix = strtolower((string) $suffix);
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
