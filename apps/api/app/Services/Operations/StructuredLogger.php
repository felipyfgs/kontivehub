<?php

namespace App\Services\Operations;

use App\Services\Audit\AuditLogger;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Logs estruturados com correlação e sanitização allowlist para adapters/jobs.
 */
final class StructuredLogger
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = [], ?int $officeId = null): void
    {
        $this->write('info', $event, $context, $officeId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = [], ?int $officeId = null): void
    {
        $this->write('warning', $event, $context, $officeId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = [], ?int $officeId = null, ?Throwable $e = null): void
    {
        if ($e !== null) {
            $context['exception_class'] = $e::class;
            $context['exception_message'] = LogSanitizer::scrubString($e->getMessage());
        }
        $this->write('error', $event, $context, $officeId);
    }

    /**
     * Resultado de chamada externa (adapter SERPRO/Integra/SEFAZ).
     *
     * @param  array<string, mixed>  $context
     */
    public function externalCall(
        string $channel,
        string $result,
        ?int $latencyMs = null,
        ?int $httpStatus = null,
        array $context = [],
        ?int $officeId = null,
    ): void {
        $payload = array_merge($context, [
            'channel' => $channel,
            'result' => $result,
            'latency_ms' => $latencyMs,
            'http_class' => $httpStatus !== null ? $this->httpClass($httpStatus) : null,
        ]);

        $this->write('info', 'ops.external_call', $payload, $officeId);

        app(OperationsMetrics::class)->increment(
            'ops.external_call',
            1,
            [
                'channel' => $channel,
                'result' => $result,
                'http_class' => $httpStatus !== null ? $this->httpClass($httpStatus) : null,
            ],
        );

        if ($latencyMs !== null) {
            app(OperationsMetrics::class)->observeLatency(
                'ops.external_call.latency_ms',
                $latencyMs,
                ['channel' => $channel, 'result' => $result],
            );
        }

        if ($httpStatus === 429) {
            app(OperationsMetrics::class)->increment('ops.http_429', 1, ['channel' => $channel]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $event, array $context, ?int $officeId): void
    {
        $safe = LogSanitizer::redact($context);
        $correlationId = $this->audit->correlationId();

        $payload = array_merge($safe, [
            'event' => $event,
            'correlation_id' => $correlationId,
            'office_id' => $officeId,
        ]);

        Log::log($level, $event, $payload);
    }

    private function httpClass(int $status): string
    {
        if ($status === 429) {
            return '429';
        }
        if ($status >= 200 && $status < 300) {
            return '2xx';
        }
        if ($status >= 400 && $status < 500) {
            return '4xx';
        }
        if ($status >= 500) {
            return '5xx';
        }

        return 'other';
    }
}
