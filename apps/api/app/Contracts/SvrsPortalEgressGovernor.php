<?php

namespace App\Contracts;

use App\DTO\Outbound\SvrsEgressReservation;
use App\DTO\Outbound\SvrsEgressReserveRequest;
use App\DTO\Outbound\SvrsEgressReserveResult;
use App\Enums\SvrsEgressBlockCause;

/**
 * Governador único de egress ao host portal SVRS (NF-e 55 + NFC-e 65).
 * Toda chamada GET/POST/redirect manual MUST passar por reserva prévia.
 */
interface SvrsPortalEgressGovernor
{
    public function cohortId(): string;

    /**
     * Reserva atômica de exchanges para uma transação lógica (tipicamente GET+POST).
     * Fail-closed: se Redis/DB indisponível, não permite rede.
     */
    public function reserve(SvrsEgressReserveRequest $request): SvrsEgressReserveResult;

    /**
     * Consome 1 exchange já reservado (GET, POST ou redirect manual).
     */
    public function consumeExchange(SvrsEgressReservation $reservation, string $kind): void;

    /**
     * Libera reserva (sucesso, falha ou cancelamento antes de iniciar).
     */
    public function release(SvrsEgressReservation $reservation, bool $completed = false): void;

    /**
     * Abre breaker global da coorte (bloqueio múltiplas consultas, contrato, admin).
     */
    public function openBreaker(
        SvrsEgressBlockCause $cause,
        ?string $templateFingerprint = null,
        ?int $retryAfterSeconds = null,
        ?int $userId = null,
        ?int $officeId = null,
    ): void;

    /**
     * Fecha breaker somente após canário válido (ou desligamento admin explícito com motivo).
     */
    public function closeBreakerAfterCanarySuccess(?int $userId = null, ?int $officeId = null): void;

    /**
     * Estende cooldown (ADMIN) — nunca antecipa next_probe_at.
     */
    public function extendCooldown(int $additionalSeconds, int $userId, ?int $officeId = null): void;

    /**
     * @return array{
     *   cohort_id: string,
     *   state: string,
     *   cause: ?string,
     *   tier: int,
     *   opened_at: ?string,
     *   next_probe_at: ?string,
     *   canary_key_mask: ?string,
     *   exchanges_hour: int,
     *   exchanges_day: int,
     *   exchanges_hour_remaining: int,
     *   exchanges_day_remaining: int,
     *   inflight: int
     * }
     */
    public function cohortHealth(): array;

    public function isCallAllowed(bool $isCanary = false): bool;

    /**
     * @return array{ok: bool, reason: string}
     */
    public function selectCanary(string $accessKeyMask, string $accessKeyHash, int $userId, ?int $officeId = null): array;

    public function assertChannelMayEnable(): void;
}
