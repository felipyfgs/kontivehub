<?php

namespace App\Enums;

enum SerproEligibilityCode: string
{
    case Eligible = 'ELIGIBLE';
    case FeatureDisabled = 'FEATURE_DISABLED';
    case KillSwitch = 'KILL_SWITCH';
    case CircuitOpen = 'CIRCUIT_OPEN';
    case SubscriptionBlocked = 'SUBSCRIPTION_BLOCKED';
    case ContractUnavailable = 'CONTRACT_UNAVAILABLE';
    case ContractUnhealthy = 'CONTRACT_UNHEALTHY';
    case AuthorizationMissing = 'AUTHORIZATION_MISSING';
    case AuthorizationActionRequired = 'AUTHORIZATION_ACTION_REQUIRED';
    case AuthorizationExpired = 'AUTHORIZATION_EXPIRED';
    case TokenMissing = 'TOKEN_MISSING';
    case TokenExpired = 'TOKEN_EXPIRED';
    case ContributorCrossTenant = 'CONTRIBUTOR_CROSS_TENANT';
    case ProxyPowerMissing = 'PROXY_POWER_MISSING';
    case ProxyPowerInsufficient = 'PROXY_POWER_INSUFFICIENT';
    case ProxyPowerExpired = 'PROXY_POWER_EXPIRED';
    case CoverageUnsupported = 'COVERAGE_UNSUPPORTED';
    case RoleForbidden = 'ROLE_FORBIDDEN';
    case BudgetExceeded = 'BUDGET_EXCEEDED';
    case RateLimited = 'RATE_LIMITED';
    case ServiceNotCataloged = 'SERVICE_NOT_CATALOGED';
    case MutatingDisabled = 'MUTATING_DISABLED';
    case RepresentationChainIncomplete = 'REPRESENTATION_CHAIN_INCOMPLETE';
    case DemoOfficeBlocked = 'DEMO_OFFICE_BLOCKED';
    case PowerMatrixReviewRequired = 'POWER_MATRIX_REVIEW_REQUIRED';
    case ProxyPowerNotAccepted = 'PROXY_POWER_NOT_ACCEPTED';
    case ProxyPowerStale = 'PROXY_POWER_STALE';
    case ProxyPowerD1Missing = 'PROXY_POWER_D1_MISSING';
    case OfficialClarificationRequired = 'OFFICIAL_CLARIFICATION_REQUIRED';
    case FreeSmokeBillableBlocked = 'FREE_SMOKE_BILLABLE_BLOCKED';
    case AuthorIdentityInvalid = 'AUTHOR_IDENTITY_INVALID';
    case EnvironmentMismatch = 'ENVIRONMENT_MISMATCH';

    public function isBlocking(): bool
    {
        return $this !== self::Eligible;
    }
}
