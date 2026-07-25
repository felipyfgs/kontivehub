<?php

namespace App\Services\Serpro;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Limites versionados de Eventos de Atualização (oficiais enquanto vigentes):
 * 1.000 PF/dia, 1.000 PJ/dia, 1.000 contribuintes/lote.
 *
 * 429: não permite retry até a janela diária (TZ configurável) reabrir.
 */
final class EventosRateLimiter
{
    public function version(): string
    {
        return (string) config('serpro.eventos.limits_version', 'v2026-07-16');
    }

    public function pfPerDay(): int
    {
        return max(0, (int) config('serpro.eventos.pf_per_day', 1000));
    }

    public function pjPerDay(): int
    {
        return max(0, (int) config('serpro.eventos.pj_per_day', 1000));
    }

    public function contributorsPerBatch(): int
    {
        return max(0, (int) config('serpro.eventos.contributors_per_batch', 1000));
    }

    public function timezone(): string
    {
        return (string) config('serpro.eventos.timezone', 'America/Sao_Paulo');
    }

    /**
     * @throws RuntimeException RATE_LIMIT_EVENTOS_* / EVENTOS_BATCH_TOO_LARGE
     */
    public function assertBatchSize(int $contributorsInBatch): void
    {
        $max = $this->contributorsPerBatch();
        if ($max <= 0) {
            throw new RuntimeException('EVENTOS_LIMIT_NOT_CONFIGURED: contributors_per_batch ausente/zero.');
        }
        if ($contributorsInBatch > $max) {
            throw new RuntimeException(
                "EVENTOS_BATCH_TOO_LARGE: lote {$contributorsInBatch} excede limite versionado {$max} ({$this->version()})."
            );
        }
    }

    /**
     * Reserva 1 solicitação no contador diário PF ou PJ (por office).
     *
     * @throws RuntimeException RATE_LIMIT_EVENTOS_DAY
     */
    public function attemptDaily(int $officeId, string $personType): void
    {
        $personType = strtoupper($personType);
        $limit = $personType === 'PF' ? $this->pfPerDay() : $this->pjPerDay();
        if ($limit <= 0) {
            throw new RuntimeException('EVENTOS_LIMIT_NOT_CONFIGURED: limite diário ausente/zero.');
        }

        if ($this->isRemote429Cooling($officeId, $personType)) {
            $until = $this->remote429Until($officeId, $personType);
            throw new RuntimeException(
                'RATE_LIMIT_EVENTOS_REMOTE_429: janela diária bloqueada até '.$until->toIso8601String().' (sem retry).'
            );
        }

        $key = $this->dailyKey($officeId, $personType);
        $ttl = $this->secondsUntilEndOfDay();

        Cache::add($key, 0, $ttl);
        $count = (int) Cache::increment($key);
        if ($count === 1) {
            try {
                Cache::put($key, 1, $ttl);
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($count > $limit) {
            throw new RuntimeException(
                "RATE_LIMIT_EVENTOS_DAY: limite {$personType} {$limit}/dia (versão {$this->version()}) atingido."
            );
        }
    }

    /**
     * Marca 429 remoto: sem retry até o fim do dia civil (TZ configurável).
     */
    public function markRemote429(int $officeId, string $personType, ?int $retryAfterSeconds = null): CarbonImmutable
    {
        $until = $this->endOfDay();
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $candidate = CarbonImmutable::now($this->timezone())->addSeconds($retryAfterSeconds);
            if ($candidate->greaterThan($until)) {
                $until = $candidate;
            }
        }

        $key = $this->remote429Key($officeId, strtoupper($personType));
        $ttl = max(1, (int) CarbonImmutable::now($this->timezone())->diffInSeconds($until, false));
        Cache::put($key, $until->getTimestamp(), $ttl);

        return $until;
    }

    public function isRemote429Cooling(int $officeId, string $personType): bool
    {
        $ts = Cache::get($this->remote429Key($officeId, strtoupper($personType)));
        if ($ts === null) {
            return false;
        }

        return CarbonImmutable::now($this->timezone())->getTimestamp() < (int) $ts;
    }

    public function remote429Until(int $officeId, string $personType): CarbonImmutable
    {
        $ts = (int) (Cache::get($this->remote429Key($officeId, strtoupper($personType))) ?? 0);
        if ($ts <= 0) {
            return $this->endOfDay();
        }

        return CarbonImmutable::createFromTimestamp($ts, $this->timezone());
    }

    private function dailyKey(int $officeId, string $personType): string
    {
        $day = CarbonImmutable::now($this->timezone())->format('Ymd');

        return sprintf('serpro:eventos:rl:%s:%s:office:%d:%s', $this->version(), $day, $officeId, strtoupper($personType));
    }

    private function remote429Key(int $officeId, string $personType): string
    {
        return sprintf('serpro:eventos:429:%s:office:%d:%s', $this->version(), $officeId, strtoupper($personType));
    }

    private function endOfDay(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->endOfDay();
    }

    private function secondsUntilEndOfDay(): int
    {
        $now = CarbonImmutable::now($this->timezone());
        $end = $now->endOfDay();
        $sec = (int) $now->diffInSeconds($end, false);

        return max(60, $sec);
    }
}
