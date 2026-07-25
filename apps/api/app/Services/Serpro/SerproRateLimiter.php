<?php

namespace App\Services\Serpro;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Rate limit atômico (Redis/cache increment) com limites versionados.
 *
 * Em egress produtivo: zero/ausente NÃO significa ilimitado — a chamada é bloqueada
 * até que limites positivos estejam configurados (SERPRO_USAGE_PRODUCTIVE_RATE_LIMIT_REQUIRED).
 */
final class SerproRateLimiter
{
    public function attempt(int $officeId, string $operationKey, bool $productiveEgress = false): void
    {
        $version = (string) config('serpro_usage.rate_limit_version', config('serpro.rate_limit.version', 'v1'));
        $global = (int) config('serpro.rate_limit.global_per_minute', 0);
        $perOffice = (int) config('serpro.rate_limit.per_office_per_minute', 0);

        /** @var array<string, array{per_minute?: int}> $operations */
        $operations = config('serpro.rate_limit.operations', []);
        $perOperation = (int) ($operations[$operationKey]['per_minute']
            ?? config('serpro.rate_limit.default_operation_per_minute', 0));

        if ($productiveEgress && (bool) config('serpro_usage.productive_rate_limit_required', true)) {
            if ($global <= 0 && $perOffice <= 0 && $perOperation <= 0) {
                throw new RuntimeException(
                    'RATE_LIMIT_NOT_CONFIGURED: limites zero/ausentes não autorizam egress produtivo SERPRO.'
                );
            }
        }

        if ($global > 0 && ! $this->hit("serpro:rl:{$version}:global", $global)) {
            throw new RuntimeException('RATE_LIMIT_LOCAL: limite global SERPRO atingido.');
        }

        if ($perOffice > 0 && ! $this->hit("serpro:rl:{$version}:office:{$officeId}", $perOffice)) {
            throw new RuntimeException('RATE_LIMIT_LOCAL: limite do escritório SERPRO atingido.');
        }

        if ($perOperation > 0 && ! $this->hit(
            'serpro:rl:'.$version.':operation:'.hash('sha256', $operationKey),
            $perOperation,
        )) {
            throw new RuntimeException('RATE_LIMIT_LOCAL: limite da operação SERPRO atingido.');
        }
    }

    /**
     * Incremento atômico com TTL de janela de 60s.
     * Usa add()+increment para reduzir corrida no array cache; em Redis o increment é atômico.
     */
    private function hit(string $key, int $maxPerMinute): bool
    {
        // Garante chave com TTL na primeira inserção atômica (Cache::add).
        Cache::add($key, 0, 60);

        $count = (int) Cache::increment($key);
        if ($count === 1) {
            // Reforça TTL se o backend ignorou o add (array driver).
            try {
                Cache::put($key, 1, 60);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $count <= $maxPerMinute;
    }
}
