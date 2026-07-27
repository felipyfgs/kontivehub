import type {
  ActivationMethod,
  CreatePlatformTenantBody,
  CreatePlatformTenantResult,
  CredentialDeliveryPayload,
  FiscalModuleAdminItem,
  FiscalModuleRestrictionBody,
  PlatformTenantAdminDetail,
  PlatformTenantAdminSummary,
  PlatformTenantSelectResult,
  PlatformFiscalModulesEnvelope,
  PlatformTenantFiscalModulesEnvelope,
  PlatformTenantsEnvelope,
  PlatformOwner,
  SerproCatalogEntry,
  SerproContractSanitized,
  SerproCredentialVersionSanitized,
  SerproExternalGateSanitized,
  SerproGlobalHealth,
  SerproKillSwitchStatus,
  SerproPlatformConfiguration,
  SerproProductionOnboardingEnvelope,
  SerproReadinessSnapshot,
  SerproUsageConsolidation,
  SerproUsageReconciliation,
  UpdatePlatformOwnerBody
} from '~/types/api'
import type { ApiClient } from './types'

/**
 * API global PLATFORM_ADMIN (prefixo /api/v1/platform/*).
 * Sem tenant context; respostas sanitizadas — nunca segredo/XML/vault id.
 *
 * Paths alinhados às rotas Laravel em /api/v1/platform/* (sem inventar singular
 * quando a API só expõe o plural, ex.: /serpro/rollouts).
 */
export function createPlatformApi(client: ApiClient) {
  return {
    platform: {
      /**
       * Seletor global de escritórios (PLATFORM_ADMIN).
       * Seleção privilegiada — não cria membership nem altera selected_tenant_id.
       * Administração (criação/pendentes): adminList / create / show / regenerate / updateFirstAdmin.
       */
      tenants: {
        list: (params?: { page?: number, per_page?: number, q?: string }) =>
          client<{ data: PlatformTenantsEnvelope }>('/api/v1/platform/tenants/selector', {
            query: params
          }),
        select: (tenantId: number) =>
          client<{ data: PlatformTenantSelectResult }>('/api/v1/platform/tenants/select', {
            method: 'POST',
            body: { tenant_id: tenantId }
          }),
        clear: () =>
          client<{ data: { access_mode: string | null } }>('/api/v1/platform/tenants/select', {
            method: 'DELETE'
          }),
        /** Lista admin (inclui PENDING_ACTIVATION). */
        adminList: (params?: { lifecycle_status?: string }) =>
          client<{ data: PlatformTenantAdminSummary[] }>('/api/v1/platform/tenants/admin', {
            query: params
          }),
        create: (body: CreatePlatformTenantBody) =>
          client<{ data: CreatePlatformTenantResult }>('/api/v1/platform/tenants', {
            method: 'POST',
            body
          }),
        show: (tenantId: number) =>
          client<{ data: PlatformTenantAdminDetail }>(`/api/v1/platform/tenants/${tenantId}`),
        regenerateActivation: (tenantId: number, body: { method: ActivationMethod }) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/platform/tenants/${tenantId}/activation/regenerate`,
            { method: 'POST', body }
          ),
        updateFirstAdmin: (
          tenantId: number,
          body: { name: string, email: string, method: ActivationMethod }
        ) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/platform/tenants/${tenantId}/first-admin`,
            { method: 'PATCH', body }
          )
      },
      fiscalModules: {
        list: () =>
          client<{ data: PlatformFiscalModulesEnvelope }>('/api/v1/platform/fiscal/modules'),
        setRestriction: (moduleKey: string, body: FiscalModuleRestrictionBody) =>
          client<{ data: FiscalModuleAdminItem, message: string }>(
            `/api/v1/platform/fiscal/modules/${encodeURIComponent(moduleKey)}/restriction`,
            { method: 'PATCH', body }
          ),
        listForTenant: (tenantId: number) =>
          client<{ data: PlatformTenantFiscalModulesEnvelope }>(
            `/api/v1/platform/tenants/${tenantId}/fiscal/modules`
          ),
        setTenantRestriction: (
          tenantId: number,
          moduleKey: string,
          body: FiscalModuleRestrictionBody
        ) =>
          client<{ data: FiscalModuleAdminItem, message: string }>(
            `/api/v1/platform/tenants/${tenantId}/fiscal/modules/${encodeURIComponent(moduleKey)}/restriction`,
            { method: 'PATCH', body }
          )
      },
      /** Proprietário singleton da instalação (PLATFORM_ADMIN). */
      owner: {
        show: () =>
          client<{ data: PlatformOwner }>('/api/v1/platform/owner'),
        update: (body: UpdatePlatformOwnerBody) =>
          client<{ data: PlatformOwner }>('/api/v1/platform/owner', {
            method: 'PATCH',
            body
          })
      },
      serpro: {
        /**
         * Configuração global unificada (Proprietário).
         * Versões de credencial são a única superfície de mutação do contrato.
         */
        configuration: {
          show: (params?: { environment?: string }) =>
            client<{ data: SerproPlatformConfiguration }>('/api/v1/platform/serpro/configuration', {
              query: params
            })
        },
        productionOnboarding: {
          show: () =>
            client<{ data: SerproProductionOnboardingEnvelope }>(
              '/api/v1/platform/serpro/production-onboarding'
            ),
          submit: (body: FormData, idempotencyKey: string) =>
            client<{ data: SerproProductionOnboardingEnvelope }>(
              '/api/v1/platform/serpro/production-onboarding',
              {
                method: 'POST',
                body,
                headers: { 'Idempotency-Key': idempotencyKey }
              }
            )
        },
        credentialVersions: {
          list: (params?: { environment?: string }) =>
            client<{ data: SerproCredentialVersionSanitized[] }>(
              '/api/v1/platform/serpro/credential-versions',
              { query: params }
            ),
          show: (id: number) =>
            client<{ data: SerproCredentialVersionSanitized }>(
              `/api/v1/platform/serpro/credential-versions/${id}`
            ),
          store: (body: FormData) =>
            client<{ data: SerproCredentialVersionSanitized }>(
              '/api/v1/platform/serpro/credential-versions',
              { method: 'POST', body }
            ),
          verify: (id: number) =>
            client<{ data: SerproCredentialVersionSanitized }>(
              `/api/v1/platform/serpro/credential-versions/${id}/verify`,
              { method: 'POST', body: {} }
            ),
          testConnection: (id: number) =>
            client<{ data: { evidence: Record<string, unknown>, credential_version: SerproCredentialVersionSanitized } }>(
              `/api/v1/platform/serpro/credential-versions/${id}/test-connection`,
              { method: 'POST', body: {} }
            ),
          activate: (id: number, body?: { approval_id?: number, reason?: string, serpro_contract_id?: number }) =>
            client<{ data: SerproCredentialVersionSanitized }>(
              `/api/v1/platform/serpro/credential-versions/${id}/activation`,
              { method: 'POST', body: body || {} }
            )
        },
        externalGates: {
          update: (gate: string, body: {
            ticket_ref: string
            answer_summary: string
            responsible_name: string
            reference_date: string
            environment?: string
          }) =>
            client<{ data: SerproExternalGateSanitized }>(
              `/api/v1/platform/serpro/external-gates/${gate}`,
              { method: 'PATCH', body }
            )
        },
        usageLimits: {
          update: (body: {
            environment: string
            cycle_start_day: number
            alert_percent: number
            global_limit_quantity?: number | null
            tenant_limits?: Array<{ tenant_id: number, limit_quantity?: number | null }>
          }) =>
            client<{ data: { config: Record<string, unknown>, tenant_limits: Array<Record<string, unknown>> } }>(
              '/api/v1/platform/serpro/usage-limits',
              { method: 'PUT', body }
            )
        },
        contracts: {
          list: (params?: { environment?: string }) =>
            client<{ data: SerproContractSanitized[] }>('/api/v1/platform/serpro/contracts', {
              query: params
            }),
          show: (id: number) =>
            client<{ data: SerproContractSanitized }>(`/api/v1/platform/serpro/contracts/${id}`)
        },
        health: (params?: { environment?: string }) =>
          client<{ data: SerproGlobalHealth }>('/api/v1/platform/serpro/health', { query: params }),
        /**
         * Readiness read-only (design 8.1/9.1). Fallback path; se 404, UI deriva do health.
         */
        readiness: (params?: { environment?: string }) =>
          client<{ data: SerproReadinessSnapshot }>('/api/v1/platform/serpro/readiness', {
            query: params
          }),
        catalog: (params?: { environment?: string }) =>
          client<{ data: SerproCatalogEntry[] }>('/api/v1/platform/serpro/catalog', {
            query: params
          }),
        killSwitch: {
          status: () =>
            client<{ data: SerproKillSwitchStatus }>('/api/v1/platform/serpro/kill-switch'),
          set: (body: {
            active: boolean
            reason: string
            solution?: string
            /** OWNER_CONFIRMATION ao desligar */
            confirmation_phrase?: string
            change_window_start?: string
            change_window_end?: string
          }) =>
            client<{
              data: SerproKillSwitchStatus
              approval?: Record<string, unknown>
              executed?: boolean
              message?: string
            }>('/api/v1/platform/serpro/kill-switch', {
              method: 'POST',
              body
            })
        },
        breakerReset: (body: { reason: string }) =>
          client<{ data: Record<string, unknown> }>('/api/v1/platform/serpro/breaker/reset', {
            method: 'POST',
            body
          }),
        /**
         * Aprovações de rollout (quatro olhos) — API real: /serpro/rollouts.
         * Snapshot de smoke/canário não existe como GET singular; a UI deriva de health/readiness.
         */
        rollouts: {
          list: (params?: { status?: string }) =>
            client<{ data: Array<Record<string, unknown>> }>('/api/v1/platform/serpro/rollouts', {
              query: params
            }),
          request: (body: Record<string, unknown>) =>
            client<{ data: Record<string, unknown> }>('/api/v1/platform/serpro/rollouts', {
              method: 'POST',
              body
            }),
          approve: (id: number, body: Record<string, unknown>) =>
            client<{ data: Record<string, unknown>, executed?: boolean }>(
              `/api/v1/platform/serpro/rollouts/${id}/approve`,
              { method: 'POST', body }
            ),
          reject: (id: number, body: { reason: string }) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/platform/serpro/rollouts/${id}/reject`,
              { method: 'POST', body }
            )
        },
        /**
         * Orçamentos globais (design). Path esperado; degradável se ausente.
         */
        budgets: {
          show: (params?: { year?: number, month?: number }) =>
            client<{ data: Record<string, unknown> }>('/api/v1/platform/serpro/budgets', {
              query: params
            })
        },
        usage: {
          consolidation: (params?: { year?: number, month?: number, recompute?: boolean }) =>
            client<{ data: SerproUsageConsolidation }>(
              '/api/v1/platform/serpro-usage/consolidation',
              { query: params }
            ),
          recompute: (body: { year: number, month: number, tenant_id?: number | null }) =>
            client<{ data: Record<string, unknown> }>('/api/v1/platform/serpro-usage/recompute', {
              method: 'POST',
              body
            }),
          registerReconciliation: (body: Record<string, unknown>) =>
            client<{ data: SerproUsageReconciliation }>(
              '/api/v1/platform/serpro-usage/reconciliations',
              { method: 'POST', body }
            )
        },
        /**
         * Canário DTE controlado — resumo global sanitizado (sem payload fiscal).
         */
        dteCanary: {
          summary: (params?: { request_id?: number }) =>
            client<{ data: Record<string, unknown> }>('/api/v1/platform/serpro/dte-canary', {
              query: params
            }),
          create: () =>
            client<{ data: Record<string, unknown> }>('/api/v1/platform/serpro/dte-canary', {
              method: 'POST',
              body: {}
            }),
          show: (id: number) =>
            client<{ data: Record<string, unknown> }>(`/api/v1/platform/serpro/dte-canary/${id}`),
          selectTarget: (id: number, body: { tenant_id: number, client_id: number }) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/platform/serpro/dte-canary/${id}/target`,
              { method: 'POST', body }
            ),
          approveOwner: (id: number) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/platform/serpro/dte-canary/${id}/approve-owner`,
              { method: 'POST', body: {} }
            ),
          execute: (id: number) =>
            client<{ data: Record<string, unknown>, replay?: boolean }>(
              `/api/v1/platform/serpro/dte-canary/${id}/execute`,
              { method: 'POST', body: {} }
            ),
          reconcile: (id: number, body: { reference: string, summary: string }) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/platform/serpro/dte-canary/${id}/reconcile`,
              { method: 'POST', body }
            ),
          promoteLimited: (id: number, body: {
            confirmation_phrase: string
            reason: string
            change_window_start?: string
            change_window_end?: string
            max_quantity?: number
          }) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/platform/serpro/dte-canary/${id}/promote-limited`,
              { method: 'POST', body }
            ),
          disable: (body: { confirmation_phrase: string, reason: string }) =>
            client<{ data: Record<string, unknown> }>(
              '/api/v1/platform/serpro/dte-canary/disable',
              { method: 'POST', body }
            )
        }
      }
    }
  }
}
