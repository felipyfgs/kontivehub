import type {
  ActivationMethod,
  CreateTenantMemberBody,
  CreateTenantMemberResult,
  CredentialDeliveryPayload,
  TenantAutXmlEnrollment,
  TenantAutXmlOverview,
  TenantCertificate,
  TenantInstitutionalProfile,
  TenantMember,
  TenantMembersMeta,
  TenantMonitorSchedulePolicy,
  TenantOnboardingActionable,
  TenantRole,
  TenantSerproAuthorization,
  TenantSubscription,
  TenantTechnicalConsent,
  TenantUsageEntry,
  TenantUsageSummary,
  PageMeta,
  SerproPlatformHealth,
  TaxProxyPower
} from '~/types/api'
import type { ApiClient } from './types'

const PURPOSE_LABELS: Record<string, string> = {
  CERTIFICATE: 'Certificado do escritório',
  SERPRO_TERM_SIGNING: 'Assinatura do Termo de autorização (automatizada)',
  NFE_AUTXML_DISTDFE: 'autXML DistDFe (NFe/CTe) do escritório'
}

function purposeItems(codes: unknown): TenantTechnicalConsent['purposes'] {
  if (!Array.isArray(codes)) return []
  return codes.map((code) => {
    const key = String(code)
    return { code: key, label: PURPOSE_LABELS[key] || key }
  })
}

/** Normaliza GET /tenant/settings/consent → TenantTechnicalConsent da UI. */
function mapTechnicalConsentStatus(raw: Record<string, unknown> | null | undefined): TenantTechnicalConsent {
  const data = raw || {}
  const active = (data.active_consent as Record<string, unknown> | null | undefined) || null
  const requires = Boolean(data.requires_consent)
  return {
    version: String(data.version_code || active?.version_code || '1'),
    accepted: active != null && active.active !== false && !requires,
    accepted_at: active?.consented_at != null ? String(active.consented_at) : null,
    purposes: purposeItems(data.purposes_presented ?? active?.purposes_presented),
    requires_reacceptance: requires,
    text_summary: undefined
  }
}

/** Normaliza POST grant/revoke (registro de consentimento) → UI. */
function mapTechnicalConsentRecord(
  raw: Record<string, unknown> | null | undefined,
  fallback: { accepted: boolean }
): TenantTechnicalConsent {
  const data = raw || {}
  const active = data.active === true || (fallback.accepted && data.revoked_at == null && data.active !== false)
  return {
    version: String(data.version_code || '1'),
    accepted: Boolean(active) && fallback.accepted,
    accepted_at: data.consented_at != null ? String(data.consented_at) : null,
    purposes: purposeItems(data.purposes_presented),
    requires_reacceptance: !fallback.accepted || data.active === false,
    text_summary: undefined
  }
}

function unwrapCertificate(
  data: { certificate?: TenantCertificate | null } | TenantCertificate | null | undefined
): TenantCertificate {
  if (data && typeof data === 'object' && 'certificate' in data) {
    return (data.certificate || data) as TenantCertificate
  }
  return data as TenantCertificate
}

export function createTenantApi(client: ApiClient) {
  return {
    tenant: {
      subscription: () =>
        client<{ data: TenantSubscription }>('/api/v1/tenant/subscription'),
      /**
       * Equipe do escritório corrente, autorizada por membership e permissão efetiva.
       * Nunca envia tenant_id — escopo só via CurrentTenant.
       */
      members: {
        list: () =>
          client<{ data: TenantMember[], meta: TenantMembersMeta }>('/api/v1/tenant/members'),
        create: (body: CreateTenantMemberBody) =>
          client<{ data: CreateTenantMemberResult }>('/api/v1/tenant/members', {
            method: 'POST',
            body
          }),
        updateRole: (membershipId: number, body: { role: TenantRole }) =>
          client<{ data: TenantMember }>(`/api/v1/tenant/members/${membershipId}`, {
            method: 'PATCH',
            body
          }),
        updateRecipient: (
          membershipId: number,
          body: { name: string, email: string, method: ActivationMethod }
        ) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/tenant/members/${membershipId}/recipient`,
            { method: 'PATCH', body }
          ),
        deactivate: (membershipId: number) =>
          client<{ data: TenantMember }>(`/api/v1/tenant/members/${membershipId}/deactivate`, {
            method: 'POST'
          }),
        reactivate: (membershipId: number, body?: { method?: ActivationMethod }) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/tenant/members/${membershipId}/reactivate`,
            { method: 'POST', body: body || {} }
          ),
        regenerateActivation: (membershipId: number, body: { method: ActivationMethod }) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/tenant/members/${membershipId}/activation/regenerate`,
            { method: 'POST', body }
          )
      },
      /**
       * Perfil institucional unificado (OpenSpec configuracao-escritorio-unificada).
       * Paths reais do backend: /api/v1/tenant/settings/* (não /tenant/profile etc.).
       * Respostas são normalizadas para o contrato da UI.
       */
      profile: {
        show: async () => {
          const res = await client<{
            data: {
              profile?: TenantInstitutionalProfile | null
            }
          }>('/api/v1/tenant/settings')
          return { data: (res.data?.profile ?? null) as TenantInstitutionalProfile }
        },
        update: async (body: {
          cnpj?: string
          legal_name?: string
          institutional_email?: string
          institutional_phone?: string
          /** Confirmação forte obrigatória na troca de CNPJ. */
          confirm_cnpj_change?: boolean
        }) => {
          const res = await client<{
            data: {
              profile: TenantInstitutionalProfile
              cnpj_changed?: boolean
            }
          }>('/api/v1/tenant/settings/profile', {
            method: 'PATCH',
            body
          })
          return { data: res.data.profile }
        }
      },
      technicalConsent: {
        show: async () => {
          const res = await client<{ data: Record<string, unknown> }>('/api/v1/tenant/settings/consent')
          return { data: mapTechnicalConsentStatus(res.data) }
        },
        accept: async (body?: { version?: string }) => {
          const res = await client<{ data: Record<string, unknown> }>('/api/v1/tenant/settings/consent', {
            method: 'POST',
            body: {
              accepted: true,
              ...(body?.version ? { version_code: body.version } : {})
            }
          })
          return { data: mapTechnicalConsentRecord(res.data, { accepted: true }) }
        },
        revoke: async () => {
          const res = await client<{ data: Record<string, unknown> }>(
            '/api/v1/tenant/settings/consent/revoke',
            { method: 'POST', body: {} }
          )
          return { data: mapTechnicalConsentRecord(res.data, { accepted: false }) }
        }
      },
      certificate: {
        show: async () => {
          const res = await client<{
            data: { certificate?: TenantCertificate | null }
          }>('/api/v1/tenant/settings/certificate')
          return { data: res.data?.certificate ?? null }
        },
        upload: async (pfx: File, password: string, options: {
          consent_accepted: boolean
          password_confirmation?: string
        }) => {
          const body = new FormData()
          body.append('pfx', pfx)
          body.append('password', password)
          body.append('consent_accepted', options.consent_accepted ? '1' : '0')
          if (options?.password_confirmation) {
            body.append('password_confirmation', options.password_confirmation)
          }
          const res = await client<{
            data: { certificate?: TenantCertificate } | TenantCertificate
          }>('/api/v1/tenant/settings/certificate', { method: 'POST', body })
          return { data: unwrapCertificate(res.data) }
        },
        replace: async (pfx: File, password: string, options?: {
          consent_accepted?: boolean
          password_confirmation?: string
          reconfirm_password?: string
        }) => {
          const body = new FormData()
          body.append('pfx', pfx)
          body.append('password', password)
          if (options?.consent_accepted != null) {
            body.append('consent_accepted', options.consent_accepted ? '1' : '0')
          }
          if (options?.password_confirmation) {
            body.append('password_confirmation', options.password_confirmation)
          }
          if (options?.reconfirm_password) {
            body.append('reconfirm_password', options.reconfirm_password)
          }
          const res = await client<{
            data: { certificate?: TenantCertificate } | TenantCertificate
          }>('/api/v1/tenant/settings/certificate/replace', { method: 'POST', body })
          return { data: unwrapCertificate(res.data) }
        },
        remove: (body?: { confirm?: boolean, reconfirm_password?: string }) =>
          client<{ data: null }>('/api/v1/tenant/settings/certificate/remove', {
            method: 'POST',
            body: { confirm: true, ...body }
          }),
        refreshIntegration: () =>
          client<{
            data: {
              status: string
              procurador_token_expires_at?: string | null
              has_procurador_token: boolean
            }
          }>('/api/v1/tenant/settings/refresh-integration', {
            method: 'POST',
            body: {}
          })
      },
      monitorSchedules: {
        list: () =>
          client<{ data: TenantMonitorSchedulePolicy[] }>('/api/v1/tenant/settings/monitor-schedules'),
        update: (monitorKey: string, body: { day_of_month: number }) =>
          client<{ data: TenantMonitorSchedulePolicy }>(
            `/api/v1/tenant/settings/monitor-schedules/${encodeURIComponent(monitorKey)}`,
            { method: 'PUT', body }
          )
      },
      onboardingStatus: () =>
        client<{ data: TenantOnboardingActionable }>('/api/v1/tenant/settings/onboarding-status'),
      serproAuthorization: {
        show: (params?: { environment?: string }) =>
          client<{
            data: TenantSerproAuthorization
            platform_health?: SerproPlatformHealth | null
            term_representation_strategy?: string | null
          }>('/api/v1/tenant/serpro-authorization', { query: params }),
        configureAuthor: (body: Record<string, unknown>) =>
          client<{ data: TenantSerproAuthorization }>('/api/v1/tenant/serpro-authorization/author', {
            method: 'POST',
            body
          }),
        uploadTermo: (body: FormData | { termo_xml?: string, environment?: string }) =>
          client<{ data: TenantSerproAuthorization }>('/api/v1/tenant/serpro-authorization/termo', {
            method: 'POST',
            body
          }),
        refreshToken: (params?: { environment?: string }) =>
          client<{ data: TenantSerproAuthorization }>('/api/v1/tenant/serpro-authorization/refresh-token', {
            method: 'POST',
            body: params || {}
          }),
        proxyPowers: (params?: {
          client_id?: number
          page?: number
          per_page?: number
          sort?: 'id' | 'client_id' | 'power_code' | 'system_code' | 'status'
          direction?: 'asc' | 'desc'
        }, options?: { signal?: AbortSignal }) =>
          client<{ data: TaxProxyPower[], meta: PageMeta }>('/api/v1/tenant/serpro-authorization/proxy-powers', {
            query: params,
            signal: options?.signal
          }),
        importProxyPower: (body: Record<string, unknown>) =>
          client<{ data: TaxProxyPower }>('/api/v1/tenant/serpro-authorization/proxy-powers', {
            method: 'POST',
            body
          }),
        syncProxyPowers: (body?: Record<string, unknown>) =>
          client<{ data?: unknown }>('/api/v1/tenant/serpro-authorization/proxy-powers/sync', {
            method: 'POST',
            body: body || {}
          }),
        eligibility: (body: Record<string, unknown>) =>
          client<{ data: Record<string, unknown> }>('/api/v1/tenant/serpro-authorization/eligibility', {
            method: 'POST',
            body
          }),
        health: (params?: { environment?: string }) =>
          client<{ data: SerproPlatformHealth }>('/api/v1/tenant/serpro-authorization/health', {
            query: params
          })
      },
      serproUsage: {
        summary: (params?: { year?: number, month?: number }) =>
          client<{ data: TenantUsageSummary }>('/api/v1/tenant/serpro-usage', { query: params }),
        entries: (params?: {
          year?: number
          month?: number
          page?: number
          per_page?: number
          sort?: 'occurred_at' | 'quantity' | 'result' | 'client_id' | 'id'
          direction?: 'asc' | 'desc'
        }) =>
          client<{ data: TenantUsageEntry[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number, per_page?: number }>(
            '/api/v1/tenant/serpro-usage/entries',
            { query: params }
          )
      },
      /** Canário DTE — confirmação do administrador do tenant e resultado fiscal. */
      dteCanary: {
        pending: () =>
          client<{ data: Record<string, unknown> | null }>('/api/v1/serpro/dte-canary/pending'),
        confirm: (id: number) =>
          client<{ data: Record<string, unknown> }>(`/api/v1/serpro/dte-canary/${id}/confirm`, {
            method: 'POST',
            body: {}
          }),
        result: (id: number) =>
          client<{ data: Record<string, unknown> }>(`/api/v1/serpro/dte-canary/${id}/result`)
      }
    },
    tenantAutXml: {
      overview: (
        params?: { page?: number, per_page?: number },
        options?: { signal?: AbortSignal }
      ) =>
        client<{ data: TenantAutXmlOverview, meta: PageMeta }>('/api/v1/tenant/autxml', {
          query: params,
          signal: options?.signal
        }),
      cursor: () =>
        client<{
          data: {
            cursors: Array<Record<string, unknown>>
            stream: {
              stream_ready: boolean
              stream_reason: string | null
              quiet_hours: number
              activated_at: string | null
              ready_at: string | null
            }
            recent_runs: Array<Record<string, unknown>>
          }
        }>('/api/v1/tenant/autxml/cursor'),
      enroll: (establishmentId: number) =>
        client<{ data: TenantAutXmlEnrollment }>('/api/v1/tenant/autxml/enrollments', {
          method: 'POST',
          body: { establishment_id: establishmentId }
        }),
      confirm: (enrollmentId: number) =>
        client<{ data: TenantAutXmlEnrollment }>(
          `/api/v1/tenant/autxml/enrollments/${enrollmentId}/confirm`,
          { method: 'POST' }
        ),
      inactivate: (enrollmentId: number) =>
        client<{ data: TenantAutXmlEnrollment }>(
          `/api/v1/tenant/autxml/enrollments/${enrollmentId}/inactivate`,
          { method: 'POST' }
        )
    }
  }
}
