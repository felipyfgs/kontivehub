<?php

namespace App\Services\Serpro;

use App\Enums\SerproBillabilityOutcome;
use App\Enums\SerproFunctionalRoute;

/**
 * Classificação oficial de faturamento por rota + HTTP status (fail-closed).
 *
 * Pré-transporte: rotas desconhecidas / regras ausentes bloqueiam egress produtivo.
 * Pós-transporte:
 *   - Apoiar/Monitorar → não bilhetados
 *   - 204/304/400/401/404/429/500/503 → não bilhetados
 *   - 200/202/403 em rota faturável → bilhetáveis
 *   - status desconhecido → POSSIBLY_BILLABLE (fail-closed conservador)
 */
final class IntegraBillingClassifier
{
    /** HTTP que nunca geram tentativa faturável (lista oficial vigente). */
    private const NON_BILLABLE_STATUSES = [204, 304, 400, 401, 404, 429, 500, 503];

    /** HTTP explicitamente bilhetáveis em rota faturável. */
    private const BILLABLE_STATUSES = [200, 202, 403];

    /**
     * Pré-transporte: a operação pode seguir para o HTTP produtivo?
     *
     * @return array{
     *   allowed: bool,
     *   outcome: SerproBillabilityOutcome,
     *   reason: string|null,
     *   expects_billable: bool
     * }
     */
    public function classifyPreTransport(?string $functionalRoute, bool $catalogKnown = true): array
    {
        if (! $catalogKnown) {
            return [
                'allowed' => false,
                'outcome' => SerproBillabilityOutcome::UnknownBlocked,
                'reason' => 'CATALOG_OR_BILLING_RULE_UNKNOWN',
                'expects_billable' => true,
            ];
        }

        if ($functionalRoute === null || $functionalRoute === '') {
            return [
                'allowed' => false,
                'outcome' => SerproBillabilityOutcome::UnknownBlocked,
                'reason' => 'ROUTE_UNKNOWN',
                'expects_billable' => true,
            ];
        }

        $route = SerproFunctionalRoute::tryFrom($functionalRoute);
        if ($route === null) {
            return [
                'allowed' => false,
                'outcome' => SerproBillabilityOutcome::UnknownBlocked,
                'reason' => 'ROUTE_NOT_IN_CATALOG',
                'expects_billable' => true,
            ];
        }

        if ($route->isNonBillableByRoute()) {
            return [
                'allowed' => true,
                'outcome' => SerproBillabilityOutcome::NonBillable,
                'reason' => null,
                'expects_billable' => false,
            ];
        }

        return [
            'allowed' => true,
            'outcome' => SerproBillabilityOutcome::Billable,
            'reason' => null,
            'expects_billable' => true,
        ];
    }

    /**
     * Pós-transporte: bilhetagem efetiva da tentativa.
     */
    public function classifyPostTransport(?string $functionalRoute, ?int $httpStatus): SerproBillabilityOutcome
    {
        if ($functionalRoute === null || $httpStatus === null) {
            // Timeout / transporte incerto sem status → possivelmente faturável.
            return SerproBillabilityOutcome::PossiblyBillable;
        }

        $route = SerproFunctionalRoute::tryFrom($functionalRoute);
        if ($route !== null && $route->isNonBillableByRoute()) {
            return SerproBillabilityOutcome::NonBillable;
        }

        if ($route === null) {
            return SerproBillabilityOutcome::PossiblyBillable;
        }

        if (in_array($httpStatus, self::NON_BILLABLE_STATUSES, true)) {
            return SerproBillabilityOutcome::NonBillable;
        }

        if (in_array($httpStatus, self::BILLABLE_STATUSES, true)) {
            return SerproBillabilityOutcome::Billable;
        }

        // Status não listado oficialmente: fail-closed conservador (não gratuidade).
        return SerproBillabilityOutcome::PossiblyBillable;
    }

    /**
     * Compat: true quando a tentativa deve entrar no ledger como faturável/possivelmente.
     */
    public function isBillableAttempt(?string $functionalRoute, ?int $httpStatus): bool
    {
        return $this->classifyPostTransport($functionalRoute, $httpStatus)->isBillableAttempt();
    }

    /**
     * @return list<int>
     */
    public function nonBillableStatuses(): array
    {
        return self::NON_BILLABLE_STATUSES;
    }

    /**
     * @return list<int>
     */
    public function billableStatuses(): array
    {
        return self::BILLABLE_STATUSES;
    }
}
