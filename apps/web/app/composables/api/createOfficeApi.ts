import type {
  ActivationMethod,
  CreateOfficeMemberBody,
  CreateOfficeMemberResult,
  CredentialDeliveryPayload,
  OfficeAutXmlEnrollment,
  OfficeAutXmlOverview,
  OfficeCanonicalCredential,
  OfficeInstitutionalProfile,
  OfficeMember,
  OfficeMembersMeta,
  OfficeMonitorSchedulePolicy,
  OfficeOnboardingActionable,
  OfficeRole,
  OfficeSerproAuthorization,
  OfficeSubscription,
  OfficeTechnicalConsent,
  OfficeUsageEntry,
  OfficeUsageSummary,
  PageMeta,
  SerproPlatformHealth,
  TaxProxyPower
} from '~/types/api'
import type { ApiClient } from './types'

const PURPOSE_LABELS: Record<string, string> = {
  CANONICAL_ECNPJ_A1: 'Credencial canônica e-CNPJ A1 do escritório',
  SERPRO_TERM_SIGNING: 'Assinatura do Termo de autorização (automatizada)',
  NFE_AUTXML_DISTDFE: 'autXML DistDFe (NFe/CTe) do escritório'
}

function purposeItems(codes: unknown): OfficeTechnicalConsent['purposes'] {
  if (!Array.isArray(codes)) return []
  return codes.map((code) => {
    const key = String(code)
    return { code: key, label: PURPOSE_LABELS[key] || key }
  })
}

/** Normaliza GET /office/settings/consent → OfficeTechnicalConsent da UI. */
function mapTechnicalConsentStatus(raw: Record<string, unknown> | null | undefined): OfficeTechnicalConsent {
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
): OfficeTechnicalConsent {
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

function unwrapCredential(
  data: { credential?: OfficeCanonicalCredential | null } | OfficeCanonicalCredential | null | undefined
): OfficeCanonicalCredential {
  if (data && typeof data === 'object' && 'credential' in data) {
    return (data.credential || data) as OfficeCanonicalCredential
  }
  return data as OfficeCanonicalCredential
}

export function createOfficeApi(client: ApiClient) {
  return {
    office: {
      subscription: () =>
        client<{ data: OfficeSubscription }>('/api/v1/office/subscription'),
      /**
       * Equipe do escritório corrente (membership ADMIN real).
       * Nunca envia office_id — escopo só via CurrentOffice.
       */
      members: {
        list: () =>
          client<{ data: OfficeMember[], meta: OfficeMembersMeta }>('/api/v1/office/members'),
        create: (body: CreateOfficeMemberBody) =>
          client<{ data: CreateOfficeMemberResult }>('/api/v1/office/members', {
            method: 'POST',
            body
          }),
        updateRole: (membershipId: number, body: { role: OfficeRole }) =>
          client<{ data: OfficeMember }>(`/api/v1/office/members/${membershipId}`, {
            method: 'PATCH',
            body
          }),
        updateRecipient: (
          membershipId: number,
          body: { name: string, email: string, method: ActivationMethod }
        ) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/office/members/${membershipId}/recipient`,
            { method: 'PATCH', body }
          ),
        deactivate: (membershipId: number) =>
          client<{ data: OfficeMember }>(`/api/v1/office/members/${membershipId}/deactivate`, {
            method: 'POST'
          }),
        reactivate: (membershipId: number, body?: { method?: ActivationMethod }) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/office/members/${membershipId}/reactivate`,
            { method: 'POST', body: body || {} }
          ),
        regenerateActivation: (membershipId: number, body: { method: ActivationMethod }) =>
          client<{ data: CredentialDeliveryPayload }>(
            `/api/v1/office/members/${membershipId}/activation/regenerate`,
            { method: 'POST', body }
          )
      },
      /**
       * Perfil institucional unificado (OpenSpec configuracao-escritorio-unificada).
       * Paths reais do backend: /api/v1/office/settings/* (não /office/profile etc.).
       * Respostas são normalizadas para o contrato da UI.
       */
      profile: {
        show: async () => {
          const res = await client<{
            data: {
              profile?: OfficeInstitutionalProfile | null
            }
          }>('/api/v1/office/settings')
          return { data: (res.data?.profile ?? null) as OfficeInstitutionalProfile }
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
              profile: OfficeInstitutionalProfile
              cnpj_changed?: boolean
            }
          }>('/api/v1/office/settings/profile', {
            method: 'PATCH',
            body
          })
          return { data: res.data.profile }
        }
      },
      technicalConsent: {
        show: async () => {
          const res = await client<{ data: Record<string, unknown> }>('/api/v1/office/settings/consent')
          return { data: mapTechnicalConsentStatus(res.data) }
        },
        accept: async (body?: { version?: string }) => {
          const res = await client<{ data: Record<string, unknown> }>('/api/v1/office/settings/consent', {
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
            '/api/v1/office/settings/consent/revoke',
            { method: 'POST', body: {} }
          )
          return { data: mapTechnicalConsentRecord(res.data, { accepted: false }) }
        }
      },
      canonicalCredential: {
        show: async () => {
          const res = await client<{
            data: { credential?: OfficeCanonicalCredential | null }
          }>('/api/v1/office/settings/credential')
          return { data: res.data?.credential ?? null }
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
            data: { credential?: OfficeCanonicalCredential } | OfficeCanonicalCredential
          }>('/api/v1/office/settings/credential', { method: 'POST', body })
          return { data: unwrapCredential(res.data) }
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
            data: { credential?: OfficeCanonicalCredential } | OfficeCanonicalCredential
          }>('/api/v1/office/settings/credential/replace', { method: 'POST', body })
          return { data: unwrapCredential(res.data) }
        },
        remove: (body?: { confirm?: boolean, reconfirm_password?: string }) =>
          client<{ data: null }>('/api/v1/office/settings/credential/remove', {
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
          }>('/api/v1/office/settings/refresh-integration', {
            method: 'POST',
            body: {}
          })
      },
      monitorSchedules: {
        list: () =>
          client<{ data: OfficeMonitorSchedulePolicy[] }>('/api/v1/office/settings/monitor-schedules'),
        update: (monitorKey: string, body: { day_of_month: number }) =>
          client<{ data: OfficeMonitorSchedulePolicy }>(
            `/api/v1/office/settings/monitor-schedules/${encodeURIComponent(monitorKey)}`,
            { method: 'PUT', body }
          )
      },
      onboardingStatus: () =>
        client<{ data: OfficeOnboardingActionable }>('/api/v1/office/settings/onboarding-status'),
      serproAuthorization: {
        show: (params?: { environment?: string }) =>
          client<{
            data: OfficeSerproAuthorization
            platform_health?: SerproPlatformHealth | null
            term_representation_strategy?: string | null
          }>('/api/v1/office/serpro-authorization', { query: params }),
        configureAuthor: (body: Record<string, unknown>) =>
          client<{ data: OfficeSerproAuthorization }>('/api/v1/office/serpro-authorization/author', {
            method: 'POST',
            body
          }),
        uploadTermo: (body: FormData | { termo_xml?: string, environment?: string }) =>
          client<{ data: OfficeSerproAuthorization }>('/api/v1/office/serpro-authorization/termo', {
            method: 'POST',
            body
          }),
        storeAuthorA1: (pfx: File, password: string, consent: boolean, environment?: string) => {
          const body = new FormData()
          body.append('pfx', pfx)
          body.append('password', password)
          body.append('consent', consent ? '1' : '0')
          if (environment) body.append('environment', environment)
          return client<{ data: OfficeSerproAuthorization }>(
            '/api/v1/office/serpro-authorization/author-a1',
            { method: 'POST', body }
          )
        },
        refreshToken: (params?: { environment?: string }) =>
          client<{ data: OfficeSerproAuthorization }>('/api/v1/office/serpro-authorization/refresh-token', {
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
          client<{ data: TaxProxyPower[], meta: PageMeta }>('/api/v1/office/serpro-authorization/proxy-powers', {
            query: params,
            signal: options?.signal
          }),
        importProxyPower: (body: Record<string, unknown>) =>
          client<{ data: TaxProxyPower }>('/api/v1/office/serpro-authorization/proxy-powers', {
            method: 'POST',
            body
          }),
        syncProxyPowers: (body?: Record<string, unknown>) =>
          client<{ data?: unknown }>('/api/v1/office/serpro-authorization/proxy-powers/sync', {
            method: 'POST',
            body: body || {}
          }),
        eligibility: (body: Record<string, unknown>) =>
          client<{ data: Record<string, unknown> }>('/api/v1/office/serpro-authorization/eligibility', {
            method: 'POST',
            body
          }),
        health: (params?: { environment?: string }) =>
          client<{ data: SerproPlatformHealth }>('/api/v1/office/serpro-authorization/health', {
            query: params
          })
      },
      serproUsage: {
        summary: (params?: { year?: number, month?: number }) =>
          client<{ data: OfficeUsageSummary }>('/api/v1/office/serpro-usage', { query: params }),
        entries: (params?: {
          year?: number
          month?: number
          page?: number
          per_page?: number
          sort?: 'occurred_at' | 'quantity' | 'result' | 'client_id' | 'id'
          direction?: 'asc' | 'desc'
        }) =>
          client<{ data: OfficeUsageEntry[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number, per_page?: number }>(
            '/api/v1/office/serpro-usage/entries',
            { query: params }
          )
      },
      /** Canário DTE — confirmação Office ADMIN e resultado fiscal (membership). */
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
    officeFiscal: {
      get: () =>
        client<{ data: { identity: Record<string, unknown> | null, credential: Record<string, unknown> | null } }>(
          '/api/v1/office/fiscal-identity'
        ),
      upsertIdentity: (body: { cnpj: string, legal_name?: string }) =>
        client<{ data: Record<string, unknown> }>('/api/v1/office/fiscal-identity', { method: 'POST', body }),
      uploadCredential: (pfx: File, password: string) => {
        const body = new FormData()
        body.append('pfx', pfx)
        body.append('password', password)
        return client<{ data: Record<string, unknown> }>('/api/v1/office/fiscal-identity/credential', {
          method: 'POST',
          body
        })
      },
      revokeCredential: (id: number) =>
        client<{ data: Record<string, unknown> }>(`/api/v1/office/fiscal-identity/credentials/${id}/revoke`, {
          method: 'POST'
        })
    },
    officeAutXml: {
      overview: (
        params?: { page?: number, per_page?: number },
        options?: { signal?: AbortSignal }
      ) =>
        client<{ data: OfficeAutXmlOverview, meta: PageMeta }>('/api/v1/office/autxml', {
          query: params,
          signal: options?.signal
        }),
      cursor: () =>
        client<{
          data: {
            cursor: Record<string, unknown> | null
            cursors?: Array<Record<string, unknown>>
            stream: {
              stream_ready: boolean
              stream_reason: string | null
              quiet_hours: number
              activated_at: string | null
              ready_at: string | null
            }
            recent_runs: Array<Record<string, unknown>>
          }
        }>('/api/v1/office/autxml/cursor'),
      enroll: (establishmentId: number) =>
        client<{ data: OfficeAutXmlEnrollment }>('/api/v1/office/autxml/enrollments', {
          method: 'POST',
          body: { establishment_id: establishmentId }
        }),
      confirm: (enrollmentId: number) =>
        client<{ data: OfficeAutXmlEnrollment }>(
          `/api/v1/office/autxml/enrollments/${enrollmentId}/confirm`,
          { method: 'POST' }
        ),
      inactivate: (enrollmentId: number) =>
        client<{ data: OfficeAutXmlEnrollment }>(
          `/api/v1/office/autxml/enrollments/${enrollmentId}/inactivate`,
          { method: 'POST' }
        )
    }
  }
}
