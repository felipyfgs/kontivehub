import type {
  ExportJob,
  OutboundCaptureProfile,
  OutboundCaptureRun,
  OutboundCapacityForecast,
  OutboundCompetenceSummary,
  OutboundDeadlineMetrics,
  OutboundDeadlinePendingItem,
  OutboundKillSwitchStatus,
  OutboundMonthlyReadiness,
  OutboundNumberState,
  OutboundSeriesCursor,
  PageMeta,
  SvrsEgressCohortHealth,
  SvrsNfceChannelSummary,
  SvrsNfceProfileSummary,
  SvrsNfceRecovery
} from '~/types/api'
import type { ApiClient } from './types'

export function createOutboundApi(client: ApiClient) {
  return {
    outbound: {
      profiles: (params?: { client_id?: number, establishment_id?: number }) =>
        client<{ data: OutboundCaptureProfile[] }>('/api/v1/outbound/profiles', { query: params }),
      profile: (id: number) =>
        client<{ data: OutboundCaptureProfile }>(`/api/v1/outbound/profiles/${id}`),
      seed: (establishmentId: number, body: { environment: string, xml?: string, file?: File }) => {
        if (body.file) {
          const fd = new FormData()
          fd.append('environment', body.environment)
          fd.append('file', body.file)
          return client<{ data: { profile: OutboundCaptureProfile, series: OutboundSeriesCursor } }>(
            `/api/v1/outbound/establishments/${establishmentId}/seed`,
            { method: 'POST', body: fd }
          )
        }
        return client<{ data: { profile: OutboundCaptureProfile, series: OutboundSeriesCursor } }>(
          `/api/v1/outbound/establishments/${establishmentId}/seed`,
          { method: 'POST', body: { environment: body.environment, xml: body.xml } }
        )
      },
      series: (profileId: number) =>
        client<{ data: OutboundSeriesCursor[] }>(`/api/v1/outbound/profiles/${profileId}/series`),
      numbers: (seriesId: number, gapsOnly?: boolean) =>
        client<{ data: OutboundNumberState[] }>(`/api/v1/outbound/series/${seriesId}/numbers`, {
          query: gapsOnly ? { gaps_only: 1 } : undefined
        }),
      runs: (params?: { series_cursor_id?: number }) =>
        client<{ data: OutboundCaptureRun[] }>('/api/v1/outbound/runs', { query: params }),
      activate: (profileId: number, body: { mandate_reference: string, allowlisted?: boolean }) =>
        client<{ data: OutboundCaptureProfile }>(`/api/v1/outbound/profiles/${profileId}/activate`, {
          method: 'POST',
          body
        }),
      uploadPackage: (profileId: number, files: File[]) => {
        const fd = new FormData()
        files.forEach(f => fd.append('files[]', f))
        return client<{ data: { imported: number, skipped: number, quarantined: number, errors: number } }>(
          `/api/v1/outbound/profiles/${profileId}/package`,
          { method: 'POST', body: fd }
        )
      },
      cscState: (profileId: number) =>
        client<{ data: { configured: boolean, csc_id?: string | null, configured_at?: string | null, csc?: string | null } }>(
          `/api/v1/outbound/profiles/${profileId}/csc`
        ),
      storeCsc: (profileId: number, body: { csc: string, csc_id: string }) =>
        client<{ data: { configured: boolean, csc_id?: string | null, configured_at?: string | null, csc?: string | null } }>(
          `/api/v1/outbound/profiles/${profileId}/csc`,
          { method: 'POST', body }
        ),
      triggerQuery: (seriesId: number) =>
        client<{ data: { queued: boolean, series_id: number } }>(
          `/api/v1/outbound/series/${seriesId}/trigger-query`,
          { method: 'POST' }
        ),
      resetSeries: (seriesId: number, body: { reason: string, discovery_position: number, confirm: boolean }) =>
        client<{ data: OutboundSeriesCursor }>(`/api/v1/outbound/series/${seriesId}/reset`, {
          method: 'POST',
          body
        }),
      killSwitchStatus: () =>
        client<{ data: OutboundKillSwitchStatus }>('/api/v1/outbound/kill-switch'),
      killSwitch: (body: { active: boolean, reason: string, profile_id?: number }) =>
        client<{ data: unknown }>('/api/v1/outbound/kill-switch', { method: 'POST', body }),
      svrsNfce: {
        summary: () =>
          client<{ data: SvrsNfceChannelSummary }>('/api/v1/outbound/svrs-nfce/summary'),
        recoveries: (params?: {
          status?: string
          profile_id?: number
          client_id?: number
          page?: number
          per_page?: number
        }) =>
          client<{ data: SvrsNfceRecovery[], meta: { current_page: number, last_page: number, total: number } }>(
            '/api/v1/outbound/svrs-nfce/recoveries',
            { query: params }
          ),
        profileSummary: (profileId: number) =>
          client<{ data: SvrsNfceProfileSummary }>(`/api/v1/outbound/svrs-nfce/profiles/${profileId}/summary`),
        enqueue: (body: { number_state_id: number }) =>
          client<{ data: SvrsNfceRecovery }>('/api/v1/outbound/svrs-nfce/recoveries', {
            method: 'POST',
            body
          }),
        retry: (recoveryId: number) =>
          client<{ data: SvrsNfceRecovery }>(`/api/v1/outbound/svrs-nfce/recoveries/${recoveryId}/retry`, {
            method: 'POST'
          }),
        killSwitchStatus: () =>
          client<{ data: { active: boolean, source?: string | null } }>('/api/v1/outbound/svrs-nfce/kill-switch'),
        killSwitch: (body: { active: boolean, reason: string }) =>
          client<{ data: { active: boolean, source?: string | null } }>('/api/v1/outbound/svrs-nfce/kill-switch', {
            method: 'POST',
            body
          }),
        breakerStatus: () =>
          client<{ data: { global: { state: string, open_until?: number | null, failures?: number } } }>(
            '/api/v1/outbound/svrs-nfce/breaker'
          ),
        breakerReset: (body: { scope: 'global' | 'root', client_id?: number, reason: string }) =>
          client<{ data: { global: { state: string } } }>('/api/v1/outbound/svrs-nfce/breaker/reset', {
            method: 'POST',
            body
          })
      },
      svrsPortalEgress: {
        health: () =>
          client<{ data: SvrsEgressCohortHealth }>('/api/v1/outbound/svrs-portal/egress'),
        extendCooldown: (body: { additional_seconds: number, reason: string }) =>
          client<{ data: SvrsEgressCohortHealth }>('/api/v1/outbound/svrs-portal/egress/extend-cooldown', {
            method: 'POST',
            body
          }),
        selectCanary: (body: { number_state_id: number, reason: string }) =>
          client<{ data: SvrsEgressCohortHealth }>('/api/v1/outbound/svrs-portal/egress/select-canary', {
            method: 'POST',
            body
          })
      },
      deadline: {
        competence: (competence: string) =>
          client<{ data: OutboundCompetenceSummary }>('/api/v1/outbound/deadline/competence', {
            query: { competence }
          }),
        capacity: (competence: string) =>
          client<{ data: OutboundCapacityForecast }>('/api/v1/outbound/deadline/capacity', {
            query: { competence }
          }),
        pending: (params?: {
          competence?: string
          urgency_band?: string
          model?: string
          root_cnpj?: string
          client_id?: number
          source?: string
          page?: number
          per_page?: number
          sort?: 'due_at' | 'urgency_band' | 'model' | 'target_at' | 'id'
          direction?: 'asc' | 'desc'
        }) =>
          client<{ data: OutboundDeadlinePendingItem[], meta: PageMeta }>('/api/v1/outbound/deadline/pending', {
            query: params
          }),
        contingencyBatch: (competence?: string) =>
          client<{ data: Array<Record<string, unknown>> }>('/api/v1/outbound/deadline/contingency-batch', {
            query: competence ? { competence } : undefined
          }),
        metrics: (competence?: string) =>
          client<{ data: OutboundDeadlineMetrics }>('/api/v1/outbound/deadline/metrics', {
            query: competence ? { competence } : undefined
          }),
        confirmPartial: (body: { competence: string, notes?: string }) =>
          client<{ data: OutboundMonthlyReadiness }>('/api/v1/outbound/deadline/confirm-partial', {
            method: 'POST',
            body
          }),
        exportMonthly: (body: { competence: string, include_events?: boolean, notes?: string }) =>
          client<{
            data: {
              export: ExportJob
              readiness: OutboundMonthlyReadiness
              has_manifest: boolean
              completeness_scope: string
            }
          }>('/api/v1/outbound/deadline/export', { method: 'POST', body }),
        advanceTarget: (body: { competence: string, target_at: string }) =>
          client<{ data: { competence: string, target_at: string, due_at: string, updated_rows: number } }>(
            '/api/v1/outbound/deadline/advance-target',
            { method: 'POST', body }
          )
      }
    }
  }
}
