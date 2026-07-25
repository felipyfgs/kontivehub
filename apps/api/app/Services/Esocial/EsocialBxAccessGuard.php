<?php

namespace App\Services\Esocial;

use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\EsocialBxAccessLedger;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EsocialBxAccessGuard
{
    public function __construct(
        private readonly EsocialBxConfig $config,
    ) {}

    public function employerHash(Client $client): string
    {
        $digits = preg_replace('/\D/', '', (string) $client->root_cnpj) ?? '';

        return hash_hmac('sha256', substr($digits, 0, 8), (string) config('app.key'));
    }

    public function assertOperationalWindow(?CarbonImmutable $now = null): void
    {
        $now = $this->now($now);
        $blocked = array_map('intval', (array) config('fgts_esocial.official_bx.blocked_days', range(1, 7)));
        if (in_array($now->day, $blocked, true)) {
            throw new EsocialBxException(
                'ESOCIAL_BX_BLOCKED_WINDOW',
                'O eSocial BX não permite consultas entre os dias 1 e 7.',
                blocked: true,
            );
        }
    }

    /** @template T
     * @param  callable():T  $callback
     * @return T
     */
    public function withEmployerLock(Client $client, string $environment, callable $callback): mixed
    {
        $this->assertEnvironment($environment);
        $key = 'esocial-bx:'.$environment.':'.$this->employerHash($client);
        $lock = Cache::lock($key, max(30, (int) config('fgts_esocial.official_bx.lock_seconds', 180)));
        if (! $lock->get()) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CONCURRENT_REQUEST',
                'Já existe uma consulta eSocial BX para este empregador.',
                retryable: true,
            );
        }

        try {
            return $callback();
        } finally {
            $this->release($lock);
        }
    }

    public function consumedToday(Client $client, string $environment, ?CarbonImmutable $now = null): int
    {
        $this->assertEnvironment($environment);
        $now = $this->now($now);

        return EsocialBxAccessLedger::query()
            ->withoutGlobalScopes()
            ->where('employer_hash', $this->employerHash($client))
            ->where('environment', $environment)
            ->whereDate('access_date', $now->toDateString())
            ->count();
    }

    public function reserve(
        Office $office,
        Client $client,
        string $environment,
        string $operation,
        ?string $correlationId = null,
        ?CarbonImmutable $now = null,
    ): EsocialBxAccessLedger {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new EsocialBxException(
                'ESOCIAL_BX_TENANT_MISMATCH',
                'Cliente não pertence ao office da operação eSocial BX.',
                blocked: true,
            );
        }
        $this->assertEnvironment($environment);
        if (preg_match('/^[A-Z0-9_-]{1,40}$/', $operation) !== 1
            || ($correlationId !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $correlationId) !== 1)) {
            throw new EsocialBxException(
                'ESOCIAL_BX_LEDGER_INPUT_INVALID',
                'Metadados do ledger eSocial BX inválidos.',
                blocked: true,
            );
        }
        $this->assertOperationalWindow($now);
        $now = $this->now($now);
        $limit = max(1, min(10, (int) config('fgts_esocial.official_bx.daily_access_limit', 10)));
        $quotaKey = implode(':', [
            'esocial-bx',
            'quota',
            $environment,
            $this->employerHash($client),
            $now->toDateString(),
        ]);
        $quotaLock = Cache::lock($quotaKey, 15);
        if (! $quotaLock->get()) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CONCURRENT_REQUEST',
                'Já existe uma reserva eSocial BX para este empregador.',
                retryable: true,
            );
        }

        try {
            return DB::transaction(function () use ($office, $client, $environment, $operation, $correlationId, $now, $limit): EsocialBxAccessLedger {
                Client::query()->withoutGlobalScopes()->whereKey($client->id)->lockForUpdate()->firstOrFail();
                if ($this->consumedToday($client, $environment, $now) >= $limit) {
                    throw new EsocialBxException(
                        'ESOCIAL_BX_QUOTA_EXHAUSTED',
                        'Cota local conservadora do eSocial BX esgotada para hoje.',
                        blocked: true,
                    );
                }

                return EsocialBxAccessLedger::query()->withoutGlobalScopes()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'employer_hash' => $this->employerHash($client),
                    'environment' => $environment,
                    'operation' => $operation,
                    'access_date' => $now->toDateString(),
                    'status' => 'RESERVED',
                    'correlation_id' => $correlationId,
                ]);
            });
        } finally {
            $this->release($quotaLock);
        }
    }

    public function finish(
        EsocialBxAccessLedger $entry,
        string $status,
        ?int $httpStatus = null,
        ?string $officialCode = null,
        bool $retryable = false,
    ): void {
        $entry->forceFill([
            'status' => mb_substr($status, 0, 24),
            'http_status' => $httpStatus,
            'official_code' => $officialCode === null ? null : mb_substr($officialCode, 0, 8),
            'retryable' => $retryable,
            'finished_at' => now(),
        ])->save();
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // TTL ainda mantém exclusão; não mascara o resultado fiscal.
        }
    }

    private function now(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now($this->config->timezone()))
            ->setTimezone($this->config->timezone());
    }

    private function assertEnvironment(string $environment): void
    {
        if (! in_array($environment, ['restricted', 'production'], true)) {
            throw new EsocialBxException(
                'ESOCIAL_BX_ENVIRONMENT_INVALID',
                'Ambiente eSocial BX inválido.',
                blocked: true,
            );
        }
    }
}
