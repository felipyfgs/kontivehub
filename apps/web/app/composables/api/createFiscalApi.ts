import type {
  FgtsCoverageManifest,
  FgtsDigitalCoverage,
  FgtsDigitalPreviewResponse,
  FgtsDigitalReadiness,
  FgtsDigitalRun,
  FgtsDigitalSessionDescriptor,
  FgtsEsocialReadiness,
  FgtsEsocialSyncAccepted,
  FgtsEsocialSyncRequest,
  FiscalCategory,
  FiscalFinding,
  FiscalMonitoringRun,
  FiscalMutationOperation,
  FiscalMutationPreflight,
  FiscalPendingItem,
  FiscalSnapshot,
  PageMeta
} from '~/types/api'
import type {
  FiscalModuleClientsPage,
  FiscalModuleOverviewResponse,
  FiscalModulePortfolioFilters,
  FiscalPortfolioModuleKey,
  PgmeiHistoryPayload,
  CcmeiHistoryPayload,
  CcmeiIssuedCertificate,
  CcmeiIssuedCertificateHistoryPayload,
  PagtowebArrecadacaoReceiptHistoryPayload,
  CcmeiRegistrationStatusHistoryPayload,
  SicalcRevenueSupportHistoryPayload,
  PagtowebPaymentCountHistoryPayload,
  PagtowebPaymentListHistoryPayload,
  DefisDeclarationsHistoryPayload,
  DefisLatestDeclarationHistoryPayload,
  DefisSpecificDeclarationHistoryPayload,
  RegimeCalendarPayload,
  RegimeOptionPayload,
  RegimeResolutionPayload,
  PgdasdCommunicationPreference,
  PgdasdCommunicationPreview,
  PgdasdCommunicationTracking,
  SitfisHistoryPayload,
  SitfisRefreshResponse,
  SitfisShowResponse,
  PgdasdHistoryPayload,
  PgdasdHistoryPeriod,
  DctfwebHistoryPayload,
  MitListaApuracoes317Payload,
  FiscalRegistrationLink,
  FiscalTaxProcess,
  ManualConsultInventory,
  ManualConsultExecuteResult,
  FiscalPnrRenunciation,
  MonitoringCoverageContract,
  InstallmentModalityCatalogItem,
  InstallmentMonitorBulkResponse,
  InstallmentOrder,
  InstallmentParcel,
  DeclarationCatalogPayload,
  DeclarationOperationMutation,
  DeclarationOperationPreflight,
  DeclarationOperationReadResult
} from '~/types/fiscal-modules'
import type { MonitoringInsightsPayload } from '~/types/monitoring-insights'
import type {
  MailboxCostPreview,
  MailboxMonitoringMode,
  MailboxMonitoringStatus,
  MailboxSyncPreview,
  MailboxSyncResponse
} from '~/types/mailbox-monitoring'
import type { ApiClient, ApiUrl } from './types'
import { createMeiPublicServicesApi } from './createMeiPublicServicesApi'

export function createFiscalApi(client: ApiClient, apiUrl: ApiUrl) {
  const meiPublicServices = createMeiPublicServicesApi(client, apiUrl)

  return {
    fiscal: {
      meiPublicServices,
      monitoringCoverage: (options?: { signal?: AbortSignal }) =>
        client<{ data: MonitoringCoverageContract }>('/api/v1/fiscal/monitoring/coverage', {
          signal: options?.signal
        }),
      monitoringInsights: (options?: { signal?: AbortSignal }) =>
        client<{ data: MonitoringInsightsPayload }>('/api/v1/fiscal/monitoring/insights', {
          signal: options?.signal
        }),
      communication: {
        updatePreference: (
          module: 'fgts' | 'sitfis' | 'mit',
          clientId: number,
          body: {
            automatic_requested: boolean
            email_enabled: boolean
            whatsapp_enabled: boolean
            lock_version: number
          }
        ) => client<{ data: PgdasdCommunicationPreference }>(
          `/api/v1/fiscal/${module}/clients/${clientId}/communication-preference`,
          { method: 'PATCH', body }
        ).then(response => response.data),
        preview: (module: 'fgts' | 'sitfis' | 'mit', clientId: number) =>
          client<{ data: PgdasdCommunicationPreview }>(
            `/api/v1/fiscal/${module}/clients/${clientId}/communication-preview`
          ).then(response => response.data),
        tracking: (module: 'fgts' | 'sitfis' | 'mit', clientId: number) =>
          client<{ data: PgdasdCommunicationTracking }>(
            `/api/v1/fiscal/${module}/clients/${clientId}/communications`
          ).then(response => response.data),
        send: (
          module: 'fgts' | 'sitfis' | 'mit',
          clientId: number,
          periodKey?: string
        ) => client<{ data: { queued: number, provider_enabled: boolean, dispatches: unknown[] } }>(
          `/api/v1/fiscal/${module}/clients/${clientId}/communication-send`,
          { method: 'POST', body: periodKey ? { period_key: periodKey } : {} }
        ).then(response => response.data)
      },
      categories: () =>
        client<{ data: FiscalCategory[] }>('/api/v1/fiscal/categories'),
      categoryLinks: {
        list: (params?: { client_id?: number, status?: string }) =>
          client<{ data: Array<Record<string, unknown>> }>('/api/v1/fiscal/category-links', {
            query: params
          }),
        associate: (body: {
          client_id: number
          fiscal_category_id: number
          coverage?: string
          status?: string
          notes?: string
        }) =>
          client<{ data: Record<string, unknown> }>('/api/v1/fiscal/category-links', {
            method: 'POST',
            body
          }),
        associateBatch: (body: {
          fiscal_category_id: number
          client_ids: number[]
          coverage?: string
        }) =>
          client<{ data: { created?: number, updated?: number, errors?: unknown[] } }>(
            '/api/v1/fiscal/category-links/batch',
            { method: 'POST', body }
          )
      },
      monitoringMembership: {
        list: (params: { module: string, submodule?: string | null }) =>
          client<{ data: Array<Record<string, unknown>> }>('/api/v1/fiscal/monitoring/membership', {
            query: {
              module: params.module,
              submodule: params.submodule || undefined
            }
          }),
        exclude: (body: { module: string, submodule?: string | null, client_ids: number[] }) =>
          client<{ data: { excluded?: number, errors?: Array<{ client_id: number, message: string }> } }>(
            '/api/v1/fiscal/monitoring/membership/exclude',
            { method: 'POST', body }
          ),
        include: (body: { module: string, submodule?: string | null, client_ids: number[] }) =>
          client<{ data: { included?: number, errors?: Array<{ client_id: number, message: string }> } }>(
            '/api/v1/fiscal/monitoring/membership/include',
            { method: 'POST', body }
          )
      },
      /**
       * Read model de carteira por módulo (overview + clients).
       * office_id só via membership ativa no backend — nunca enviar no query.
       */
      modules: {
        overview: <M extends FiscalPortfolioModuleKey>(module: M, params?: FiscalModulePortfolioFilters) =>
          client<FiscalModuleOverviewResponse<M>>(
            `/api/v1/fiscal/modules/${encodeURIComponent(module)}/overview`,
            { query: params }
          ),
        clients: <M extends FiscalPortfolioModuleKey>(
          module: M,
          params?: FiscalModulePortfolioFilters,
          options?: { signal?: AbortSignal }
        ) =>
          client<FiscalModuleClientsPage<M>>(
            `/api/v1/fiscal/modules/${encodeURIComponent(module)}/clients`,
            { query: params, signal: options?.signal }
          )
      },
      runs: {
        list: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          status?: string
          system_code?: string
        }) =>
          client<{ data: FiscalMonitoringRun[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number, per_page?: number }>(
            '/api/v1/fiscal/runs',
            { query: params }
          ),
        get: (id: number) =>
          client<{ data: FiscalMonitoringRun }>(`/api/v1/fiscal/runs/${id}`),
        create: (body: Record<string, unknown>) =>
          client<{ data: FiscalMonitoringRun }>('/api/v1/fiscal/runs', { method: 'POST', body })
      },
      snapshots: {
        list: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          current_only?: boolean | 0 | 1
          situation?: string
        }) =>
          client<{ data: FiscalSnapshot[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number, per_page?: number }>(
            '/api/v1/fiscal/snapshots',
            { query: params }
          ),
        get: (id: number) =>
          client<{ data: FiscalSnapshot }>(`/api/v1/fiscal/snapshots/${id}`)
      },
      pgdasd: {
        history: (clientId: number, params?: { year?: number }) =>
          client<{ data: PgdasdHistoryPayload | PgdasdHistoryPeriod[] }>(
            `/api/v1/fiscal/simples-mei/pgdasd/clients/${clientId}/history`,
            { query: params }
          ),
        collectDocuments: (
          clientId: number,
          body: { period_key: string, declaration_number?: string | null, confirmed: true }
        ) =>
          client<{ data: Record<string, unknown> }>(
            `/api/v1/fiscal/simples-mei/pgdasd/clients/${clientId}/documents`,
            { method: 'POST', body }
          ),
        artifactDownloadUrl: (artifactId: number) =>
          apiUrl(`/api/v1/fiscal/simples-mei/pgdasd/artifacts/${artifactId}/download`),
        communication: {
          updatePreference: (
            clientId: number,
            body: {
              automatic_requested: boolean
              email_enabled: boolean
              whatsapp_enabled: boolean
              lock_version: number
            }
          ) =>
            client<{ data: PgdasdCommunicationPreference }>(
              `/api/v1/fiscal/simples-mei/pgdasd/clients/${clientId}/communication-preference`,
              { method: 'PATCH', body }
            ),
          updateBulk: (body: { client_ids: number[], automatic_requested: boolean }) =>
            client<{
              data: PgdasdCommunicationPreference[]
              updated_count?: number
            }>(
              '/api/v1/fiscal/simples-mei/pgdasd/communication-preferences/bulk',
              { method: 'PATCH', body }
            ),
          preview: (clientId: number) =>
            client<{ data: PgdasdCommunicationPreview }>(
              `/api/v1/fiscal/simples-mei/pgdasd/clients/${clientId}/communication-preview`
            ),
          tracking: (clientId: number) =>
            client<{ data: PgdasdCommunicationTracking }>(
              `/api/v1/fiscal/simples-mei/pgdasd/clients/${clientId}/communications`
            ),
          send: (clientId: number) =>
            client<{ data: { queued: number, provider_enabled: boolean, dispatches: unknown[] } }>(
              `/api/v1/fiscal/simples-mei/pgdasd/clients/${clientId}/communication-send`,
              { method: 'POST' }
            )
        }
      },
      pgmei: {
        history: (clientId: number, params: { year: number }) =>
          client<{ data: PgmeiHistoryPayload }>(
            `/api/v1/fiscal/simples-mei/pgmei/clients/${clientId}/history`,
            { query: params }
          ),
        consult: (body: { client_ids: number[], year: number, confirmed: true }) =>
          client<{ data: FiscalMonitoringRun[], enqueued_count?: number, year?: number }>(
            '/api/v1/fiscal/simples-mei/pgmei/consult',
            { method: 'POST', body }
          ),
        communication: {
          updatePreference: (
            clientId: number,
            body: {
              automatic_requested: boolean
              email_enabled: boolean
              whatsapp_enabled: boolean
              lock_version: number
            }
          ) =>
            client<{ data: PgdasdCommunicationPreference }>(
              `/api/v1/fiscal/simples-mei/pgmei/clients/${clientId}/communication-preference`,
              { method: 'PATCH', body }
            ),
          updateBulk: (body: { client_ids: number[], automatic_requested: boolean }) =>
            client<{
              data: PgdasdCommunicationPreference[]
              updated_count?: number
            }>(
              '/api/v1/fiscal/simples-mei/pgmei/communication-preferences/bulk',
              { method: 'PATCH', body }
            ),
          preview: (clientId: number) =>
            client<{ data: PgdasdCommunicationPreview }>(
              `/api/v1/fiscal/simples-mei/pgmei/clients/${clientId}/communication-preview`
            ),
          tracking: (clientId: number) =>
            client<{ data: PgdasdCommunicationTracking }>(
              `/api/v1/fiscal/simples-mei/pgmei/clients/${clientId}/communications`
            ),
          send: (clientId: number) =>
            client<{ data: { queued: number, provider_enabled: boolean, dispatches: unknown[] } }>(
              `/api/v1/fiscal/simples-mei/pgmei/clients/${clientId}/communication-send`,
              { method: 'POST' }
            )
        }
      },
      ccmei: {
        history: (clientId: number) =>
          client<{ data: CcmeiHistoryPayload }>(
            `/api/v1/fiscal/simples-mei/ccmei/clients/${clientId}/history`
          ),
        consult: (clientId: number) =>
          client<{ data: Record<string, unknown> }>(
            `/api/v1/fiscal/simples-mei/ccmei/clients/${clientId}/consult`,
            { method: 'POST', body: { confirmed: true } }
          ),
        issuedCertificates: {
          history: (clientId: number) =>
            client<{ data: CcmeiIssuedCertificateHistoryPayload }>(
              `/api/v1/fiscal/simples-mei/ccmei/clients/${clientId}/issued-certificates`
            ),
          issue: (clientId: number) =>
            client<{ data: { success: boolean, certificate?: CcmeiIssuedCertificate, error_code?: string, error_message?: string } }>(
              `/api/v1/fiscal/simples-mei/ccmei/clients/${clientId}/issued-certificates`,
              { method: 'POST', body: { confirmed: true } }
            ),
          downloadPath: (clientId: number, certificateId: number) =>
            `/api/v1/fiscal/simples-mei/ccmei/clients/${clientId}/issued-certificates/${certificateId}/download`
        },
        registrationStatus: {
          history: (clientId: number) =>
            client<{ data: CcmeiRegistrationStatusHistoryPayload }>(
              `/api/v1/fiscal/simples-mei/ccmei/registration-status/clients/${clientId}/history`
            ),
          consult: (clientId: number) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/fiscal/simples-mei/ccmei/registration-status/clients/${clientId}/consult`,
              { method: 'POST', body: { confirmed: true } }
            )
        }
      },
      sicalcRevenueSupport: {
        history: (clientId: number, revenueCode?: string) =>
          client<{ data: SicalcRevenueSupportHistoryPayload }>(
            `/api/v1/fiscal/guides/revenue-support/clients/${clientId}/history`,
            { query: revenueCode ? { codigo_receita: revenueCode } : undefined }
          ),
        consult: (clientId: number, revenueCode: string) =>
          client<{ data: Record<string, unknown> }>(
            `/api/v1/fiscal/guides/revenue-support/clients/${clientId}/consult`,
            { method: 'POST', body: { confirmed: true, codigo_receita: revenueCode } }
          )
      },
      pagtowebPaymentCount: {
        history: (clientId: number) =>
          client<{ data: PagtowebPaymentCountHistoryPayload }>(
            `/api/v1/fiscal/guides/payment-count/clients/${clientId}/history`
          ),
        consult: (clientId: number, filters: Record<string, unknown>) =>
          client<{ data: Record<string, unknown> }>(
            `/api/v1/fiscal/guides/payment-count/clients/${clientId}/consult`,
            { method: 'POST', body: { confirmed: true, filters } }
          )
      },
      pagtowebPaymentList: {
        history: (clientId: number, params?: { page?: number, per_page?: number }) =>
          client<{ data: PagtowebPaymentListHistoryPayload }>(
            `/api/v1/fiscal/guides/payments/clients/${clientId}/history`,
            { query: params }
          ),
        consult: (clientId: number, filters: Record<string, unknown>) =>
          client<{ data: Record<string, unknown> }>(
            `/api/v1/fiscal/guides/payments/clients/${clientId}/consult`,
            { method: 'POST', body: { confirmed: true, filters } }
          )
      },
      pagtowebArrecadacaoReceipt: {
        history: (clientId: number) =>
          client<{ data: PagtowebArrecadacaoReceiptHistoryPayload }>(
            `/api/v1/fiscal/guides/receipts/clients/${clientId}/history`
          ),
        request: (clientId: number, numeroDocumento: string) =>
          client<{ data: Record<string, unknown> }>(
            `/api/v1/fiscal/guides/receipts/clients/${clientId}/request`,
            { method: 'POST', body: { confirmed: true, numeroDocumento } }
          ),
        downloadPath: (clientId: number, receiptId: number) =>
          `/api/v1/fiscal/guides/receipts/clients/${clientId}/${receiptId}/download`
      },
      findings: (params?: {
        page?: number
        per_page?: number
        client_id?: number
        active_only?: boolean | 0 | 1
      }) =>
        client<{ data: FiscalFinding[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number, per_page?: number }>(
          '/api/v1/fiscal/findings',
          { query: params }
        ),
      pending: (params?: {
        page?: number
        per_page?: number
        client_id?: number
        status?: string
        situation?: string
      }) =>
        client<{ data: FiscalPendingItem[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number, per_page?: number }>(
          '/api/v1/fiscal/pending-items',
          { query: params }
        ),
      evidenceDownloadUrl: (id: number) =>
        apiUrl(`/api/v1/fiscal/evidence/${id}/download`),
      dctfweb: {
        list: (params?: { page?: number, per_page?: number, client_id?: number }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/dctfweb/declarations',
            { query: params }
          ),
        get: (id: number) =>
          client<{ data: Record<string, unknown>, evidence_versions?: Array<Record<string, unknown>> }>(
            `/api/v1/fiscal/dctfweb/declarations/${id}`
          ),
        consult: (body: { client_id: number, period_key?: string, operation_code?: string, correlation_id?: string }) =>
          client<{ data: FiscalMonitoringRun }>('/api/v1/fiscal/dctfweb/consult', { method: 'POST', body }),
        transmit: (body: Record<string, unknown>) =>
          client<{ data: unknown }>('/api/v1/fiscal/dctfweb/transmit', { method: 'POST', body }),
        history: (clientId: number, params?: { year?: number }) =>
          client<{ data: DctfwebHistoryPayload }>(
            `/api/v1/fiscal/dctfweb/clients/${clientId}/history`,
            { query: params }
          ),
        evidenceDownloadUrl: (clientId: number, evidenceId: number) =>
          apiUrl(`/api/v1/fiscal/dctfweb/clients/${clientId}/evidence/${evidenceId}/download`),
        communication: {
          updatePreference: (
            clientId: number,
            body: {
              automatic_requested: boolean
              email_enabled: boolean
              whatsapp_enabled: boolean
              lock_version: number
            }
          ) =>
            client<{ data: PgdasdCommunicationPreference }>(
              `/api/v1/fiscal/dctfweb/clients/${clientId}/communication-preference`,
              { method: 'PATCH', body }
            ),
          updateBulk: (body: { client_ids: number[], automatic_requested: boolean }) =>
            client<{
              data: PgdasdCommunicationPreference[]
              updated_count?: number
            }>(
              '/api/v1/fiscal/dctfweb/communication-preferences/bulk',
              { method: 'PATCH', body }
            ),
          preview: (clientId: number) =>
            client<{ data: PgdasdCommunicationPreview }>(
              `/api/v1/fiscal/dctfweb/clients/${clientId}/communication-preview`
            ),
          tracking: (clientId: number) =>
            client<{ data: PgdasdCommunicationTracking }>(
              `/api/v1/fiscal/dctfweb/clients/${clientId}/communications`
            ),
          send: (clientId: number) =>
            client<{ data: { queued: number, provider_enabled: boolean, dispatches: unknown[] } }>(
              `/api/v1/fiscal/dctfweb/clients/${clientId}/communication-send`,
              { method: 'POST' }
            )
        }
      },
      mit: {
        list: (params?: { page?: number, per_page?: number, client_id?: number }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/mit/apuracoes',
            { query: params }
          ),
        get: (id: number) =>
          client<{ data: Record<string, unknown> }>(`/api/v1/fiscal/mit/apuracoes/${id}`),
        consult: (body: Record<string, unknown>) =>
          client<{ data: unknown }>('/api/v1/fiscal/mit/consult', { method: 'POST', body }),
        /** Leitura exclusiva da projeção já persistida por LISTAAPURACOES317. */
        listaApuracoes: (clientId: number, params?: { year?: number }) =>
          client<MitListaApuracoes317Payload>(
            '/api/v1/fiscal/mit/lista-apuracoes',
            { query: { client_id: clientId, ...params } }
          ),
        enqueueListaApuracoes: (body: {
          client_id: number
          anoApuracao: number
          mesApuracao?: number
          situacaoApuracao?: number
        }) =>
          client<{ data: { id?: number }, serpro_call?: 'QUEUED' }>(
            '/api/v1/fiscal/mit/lista-apuracoes',
            { method: 'POST', body }
          ),
        encerrar: (body: Record<string, unknown>) =>
          client<{ data: unknown }>('/api/v1/fiscal/mit/encerrar', { method: 'POST', body })
      },
      installments: {
        modalities: () =>
          client<{ data: InstallmentModalityCatalogItem[] }>('/api/v1/fiscal/installments/modalities'),
        orders: (params?: { page?: number, per_page?: number, client_id?: number, modality?: string }) =>
          client<{ data: InstallmentOrder[], meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/installments/orders',
            { query: params }
          ),
        order: (id: number) =>
          client<{ data: InstallmentOrder }>(`/api/v1/fiscal/installments/orders/${id}`),
        parcels: (params?: { page?: number, per_page?: number, client_id?: number, order_id?: number, modality?: string }) =>
          client<{ data: InstallmentParcel[], meta?: PageMeta }>('/api/v1/fiscal/installments/parcels', {
            query: params
          }),
        guides: (params?: { page?: number, per_page?: number, client_id?: number }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta }>('/api/v1/fiscal/installments/guides', {
            query: params
          }),
        enqueue: (body: Record<string, unknown>) =>
          client<{ data: unknown }>('/api/v1/fiscal/installments/runs', { method: 'POST', body }),
        monitorAll: (body: { client_ids: number[], correlation_id?: string }) =>
          client<{ data: InstallmentMonitorBulkResponse }>('/api/v1/fiscal/installments/monitor', {
            method: 'POST',
            body
          })
      },
      sitfis: {
        history: (clientId: number) =>
          client<{ data: SitfisHistoryPayload }>(
            `/api/v1/fiscal/sitfis/clients/${clientId}/history`
          ),
        show: (clientId: number) =>
          client<{ data: SitfisShowResponse }>('/api/v1/fiscal/sitfis', {
            query: { client_id: clientId }
          }),
        refresh: (body: { client_id: number, force?: boolean }) =>
          client<{ data: SitfisRefreshResponse }>('/api/v1/fiscal/sitfis/refresh', {
            method: 'POST',
            body
          }),
        communication: {
          updatePreference: (
            clientId: number,
            body: {
              automatic_requested: boolean
              email_enabled: boolean
              whatsapp_enabled: boolean
              lock_version: number
            }
          ) =>
            client<{ data: PgdasdCommunicationPreference }>(
              `/api/v1/fiscal/sitfis/clients/${clientId}/communication-preference`,
              { method: 'PATCH', body }
            ),
          preview: (clientId: number) =>
            client<{ data: PgdasdCommunicationPreview }>(
              `/api/v1/fiscal/sitfis/clients/${clientId}/communication-preview`
            ),
          tracking: (clientId: number) =>
            client<{ data: PgdasdCommunicationTracking }>(
              `/api/v1/fiscal/sitfis/clients/${clientId}/communications`
            ),
          send: (clientId: number) =>
            client<{ data: { queued: number, provider_enabled: boolean, dispatches: unknown[] } }>(
              `/api/v1/fiscal/sitfis/clients/${clientId}/communication-send`,
              { method: 'POST' }
            )
        }
      },
      registrations: {
        list: (params?: { page?: number, per_page?: number, q?: string, client_id?: number, status?: string }) =>
          client<{ data: FiscalRegistrationLink[], meta?: PageMeta }>('/api/v1/fiscal/registrations', {
            query: params
          }),
        forClient: (clientId: number) =>
          client<{ data: { client_id: number, links: FiscalRegistrationLink[] } }>(
            `/api/v1/fiscal/clients/${clientId}/registrations`
          ),
        refresh: (clientId: number) =>
          client<{ data: { queued: boolean, client_id: number } }>(
            `/api/v1/fiscal/clients/${clientId}/registrations/refresh`,
            { method: 'POST' }
          )
      },
      pnrRenunciations: {
        forClient: (clientId: number) => client<{ data: { client_id: number, renunciations: FiscalPnrRenunciation[] } }>(`/api/v1/fiscal/clients/${clientId}/pnr-renunciations`),
        history: (clientId: number, body: { dt_inicio?: string, dt_fim?: string, page?: number, page_size?: number }) => client<{ data: { success: boolean, count?: number, error_code?: string, error_message?: string } }>(`/api/v1/fiscal/clients/${clientId}/pnr-renunciations/history`, { method: 'POST', body }),
        status: (clientId: number, id_solicitacao: string) => client<{ data: { success: boolean, renunciation_id?: number | null, error_code?: string, error_message?: string } }>(`/api/v1/fiscal/clients/${clientId}/pnr-renunciations/status`, { method: 'POST', body: { id_solicitacao } }),
        receipt: (clientId: number, renunciation_id: number) => client<{ data: { success: boolean, renunciation_id?: number, error_code?: string, error_message?: string } }>(`/api/v1/fiscal/clients/${clientId}/pnr-renunciations/receipt`, { method: 'POST', body: { renunciation_id } })
      },
      taxProcesses: {
        list: (params?: { page?: number, per_page?: number, q?: string, client_id?: number, status?: string }) =>
          client<{ data: FiscalTaxProcess[], meta?: PageMeta }>('/api/v1/fiscal/tax-processes', {
            query: params
          }),
        forClient: (clientId: number) =>
          client<{ data: { client_id: number, processes: FiscalTaxProcess[] } }>(
            `/api/v1/fiscal/clients/${clientId}/tax-processes`
          ),
        get: (id: number) =>
          client<{ data: Record<string, unknown> }>(`/api/v1/fiscal/tax-processes/${id}`),
        refresh: (clientId: number) =>
          client<{ data: { queued: boolean, client_id: number } }>(
            `/api/v1/fiscal/clients/${clientId}/tax-processes/refresh`,
            { method: 'POST' }
          )
      },
      mailbox: {
        list: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          triage_status?: string
        }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/mailbox/messages',
            { query: params }
          ),
        get: (id: number) =>
          client<{ data: Record<string, unknown>, meta?: Record<string, unknown> }>(
            `/api/v1/fiscal/mailbox/messages/${id}`
          ),
        triage: (id: number, body: { triage_status: string, note?: string }) =>
          client<{ data: Record<string, unknown>, meta?: Record<string, unknown> }>(
            `/api/v1/fiscal/mailbox/messages/${id}/triage`,
            { method: 'PATCH', body }
          ),
        state: (params?: { client_id?: number }) =>
          client<{ data: Record<string, unknown> }>('/api/v1/fiscal/mailbox/state', { query: params }),
        alerts: (params?: { client_id?: number }) =>
          client<{ data: Array<Record<string, unknown>> }>('/api/v1/fiscal/mailbox/alerts', { query: params }),
        monitoring: {
          get: () =>
            client<{ data: MailboxMonitoringStatus }>('/api/v1/fiscal/mailbox/monitoring'),
          update: (body: {
            enabled?: boolean
            mode?: MailboxMonitoringMode
            daily_time?: string
            reconciliation_days?: number
            auto_detail_limit?: number
            monthly_budget_micros?: number | null
          }) => client<{ data: MailboxMonitoringStatus }>('/api/v1/fiscal/mailbox/monitoring', {
            method: 'PATCH',
            body
          }),
          preview: (body: { force_all?: boolean }) =>
            client<{ data: MailboxSyncPreview }>('/api/v1/fiscal/mailbox/monitoring/preview', {
              method: 'POST',
              body
            }),
          sync: (body: { force_all?: boolean, idempotency_key: string }) =>
            client<{ data: MailboxSyncResponse }>('/api/v1/fiscal/mailbox/monitoring/sync', {
              method: 'POST',
              body
            })
        },
        detailPreview: (id: number) =>
          client<{ data: { has_body: boolean, cost: MailboxCostPreview } }>(
            `/api/v1/fiscal/mailbox/messages/${id}/detail-preview`
          ),
        enqueueDetail: (id: number) =>
          client<{ data: { status: 'ACCEPTED', run_id: number, cost: MailboxCostPreview } }>(
            `/api/v1/fiscal/mailbox/messages/${id}/detail`,
            { method: 'POST' }
          ),
        bodyDownloadUrl: (id: number) =>
          apiUrl(`/api/v1/fiscal/mailbox/messages/${id}/body`),
        attachmentDownloadUrl: (messageId: number, attachmentId: number) =>
          apiUrl(`/api/v1/fiscal/mailbox/messages/${messageId}/attachments/${attachmentId}`)
      },
      declarations: {
        catalog: () =>
          client<{ data: DeclarationCatalogPayload }>('/api/v1/fiscal/declarations/catalog'),
        summary: (params?: { client_id?: number }) =>
          client<{ data: Record<string, unknown> }>('/api/v1/fiscal/declarations/summary', { query: params }),
        list: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          status?: string
          competence?: string
        }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/declarations',
            { query: params }
          ),
        get: (id: number) =>
          client<{ data: Record<string, unknown> }>(`/api/v1/fiscal/declarations/${id}`),
        operations: {
          read: (
            actionId: string,
            body: { client_id: number, confirmed: true, params: Record<string, unknown> }
          ) => client<{ data: DeclarationOperationReadResult }>(
            `/api/v1/fiscal/declarations/operations/${encodeURIComponent(actionId)}/read`,
            { method: 'POST', body }
          ),
          preflight: (
            actionId: string,
            body: { client_id: number, idempotency_key: string, params: Record<string, unknown> }
          ) => client<{ data: DeclarationOperationPreflight }>(
            `/api/v1/fiscal/declarations/operations/${encodeURIComponent(actionId)}/preflight`,
            { method: 'POST', body }
          ),
          execute: (
            actionId: string,
            body: {
              client_id: number
              idempotency_key: string
              preflight_token: string
              confirmation_phrase: string
              confirmed: boolean
              params: Record<string, unknown>
            }
          ) => client<{ data: DeclarationOperationMutation }>(
            `/api/v1/fiscal/declarations/operations/${encodeURIComponent(actionId)}/execute`,
            { method: 'POST', body }
          ),
          getMutation: (id: number) => client<{ data: DeclarationOperationMutation }>(
            `/api/v1/fiscal/declarations/operations/mutations/${id}`
          ),
          reconcile: (id: number) => client<{ data: DeclarationOperationMutation }>(
            `/api/v1/fiscal/declarations/operations/mutations/${id}/reconcile`,
            { method: 'POST' }
          )
        }
      },
      guides: {
        list: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          competence?: string
          payment_status?: string
          sort?: 'client_id' | 'system_code' | 'competence' | 'amount' | 'due_at' | 'payment_status'
          direction?: 'asc' | 'desc'
        }, options?: { signal?: AbortSignal }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/guides',
            { query: params, signal: options?.signal }
          ),
        get: (id: number) =>
          client<{ data: Record<string, unknown> }>(`/api/v1/fiscal/guides/${id}`),
        preflight: (body: Record<string, unknown>) =>
          client<{ data: Record<string, unknown> }>('/api/v1/fiscal/guides/preflight', {
            method: 'POST',
            body
          }),
        issueDownloadToken: (id: number) =>
          client<{
            data: {
              token: string
              expires_at?: string
              version_id?: number
              download_path: string
            }
          }>(`/api/v1/fiscal/guides/${id}/download-token`, { method: 'POST' }),
        downloadUrl: (token: string) =>
          apiUrl(`/api/v1/fiscal/guides/downloads/${encodeURIComponent(token)}`)
      },
      simplesMei: {
        catalog: () =>
          client<{ data: Array<Record<string, unknown>> }>('/api/v1/fiscal/simples-mei/catalog'),
        regimes: (clientId: number) =>
          client<{ data: Array<Record<string, unknown>> }>(
            `/api/v1/fiscal/simples-mei/clients/${clientId}/regimes`
          ),
        regimeCalendar: {
          list: (clientId: number) =>
            client<RegimeCalendarPayload>(
              `/api/v1/fiscal/simples-mei/clients/${clientId}/regime-calendar`
            ),
          consult: (body: { client_id: number, correlation_id?: string }) =>
            client<{ data: Record<string, unknown>, serpro_call: 'QUEUED' }>(
              '/api/v1/fiscal/simples-mei/regime-calendar/consult',
              { method: 'POST', body }
            )
        },
        defis: {
          history: (clientId: number) =>
            client<{ data: DefisDeclarationsHistoryPayload }>(
              `/api/v1/fiscal/simples-mei/defis/clients/${clientId}/history`
            ),
          consult: (clientId: number) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/fiscal/simples-mei/defis/clients/${clientId}/consult`,
              { method: 'POST', body: { confirmed: true } }
            )
        },
        defisLatestDeclaration: {
          history: (clientId: number, year?: number) =>
            client<{ data: DefisLatestDeclarationHistoryPayload }>(
              `/api/v1/fiscal/simples-mei/defis/latest-declaration/clients/${clientId}/history${year ? `?year=${year}` : ''}`
            ),
          consult: (clientId: number, calendarYear: number) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/fiscal/simples-mei/defis/latest-declaration/clients/${clientId}/consult`,
              { method: 'POST', body: { confirmed: true, calendar_year: calendarYear } }
            )
        },
        defisSpecificDeclaration: {
          history: (clientId: number, referenceId?: number) =>
            client<{ data: DefisSpecificDeclarationHistoryPayload }>(
              `/api/v1/fiscal/simples-mei/defis/specific-declaration/clients/${clientId}/history`,
              { query: referenceId ? { reference_id: referenceId } : undefined }
            ),
          consult: (clientId: number, referenceId: number) =>
            client<{ data: Record<string, unknown> }>(
              `/api/v1/fiscal/simples-mei/defis/specific-declaration/clients/${clientId}/consult`,
              { method: 'POST', body: { confirmed: true, reference_id: referenceId } }
            )
        },
        regimeOption: {
          list: (clientId: number) =>
            client<RegimeOptionPayload>(
              `/api/v1/fiscal/simples-mei/clients/${clientId}/regime-options`
            ),
          consult: (body: { client_id: number, year: number, correlation_id?: string }) =>
            client<{ data: Record<string, unknown>, serpro_call: 'QUEUED' }>(
              '/api/v1/fiscal/simples-mei/regime-option/consult',
              { method: 'POST', body }
            )
        },
        regimeResolution: {
          list: (clientId: number, year?: number) =>
            client<RegimeResolutionPayload>(
              `/api/v1/fiscal/simples-mei/clients/${clientId}/regime-resolutions`,
              { query: year ? { year } : undefined }
            ),
          consult: (body: { client_id: number, year: number, correlation_id?: string }) =>
            client<{ data: Record<string, unknown>, serpro_call: 'QUEUED' }>(
              '/api/v1/fiscal/simples-mei/regime-resolution/consult',
              { method: 'POST', body }
            )
        },
        competences: (clientId: number) =>
          client<{ data: Array<Record<string, unknown>> }>(
            `/api/v1/fiscal/simples-mei/clients/${clientId}/competences`
          ),
        snapshots: (clientId: number, params?: { page?: number, per_page?: number }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta }>(
            `/api/v1/fiscal/simples-mei/clients/${clientId}/snapshots`,
            { query: params }
          ),
        consult: (body: Record<string, unknown>) =>
          client<{ data: unknown }>('/api/v1/fiscal/simples-mei/consult', { method: 'POST', body })
      },
      fgts: {
        coverage: () =>
          client<{ data: FgtsCoverageManifest }>('/api/v1/fiscal/fgts/coverage'),
        readiness: (clientId: number) =>
          client<{ data: FgtsEsocialReadiness }>('/api/v1/fiscal/fgts/readiness', {
            query: { client_id: clientId }
          }),
        competences: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          competence_period_key?: string
        }) =>
          client<{ data: Array<Record<string, unknown>>, meta?: PageMeta, current_page?: number, last_page?: number, total?: number }>(
            '/api/v1/fiscal/fgts/competences',
            { query: params }
          ),
        competence: (id: number) =>
          client<{
            data: Record<string, unknown>
            events?: Array<Record<string, unknown>>
            coverage?: FgtsCoverageManifest
          }>(`/api/v1/fiscal/fgts/competences/${id}`),
        events: (params?: {
          page?: number
          per_page?: number
          client_id?: number
          competence_period_key?: string
          event_code?: string
        }) =>
          client<{
            data: Array<Record<string, unknown>>
            meta?: PageMeta
            coverage?: Record<string, unknown>
          }>('/api/v1/fiscal/fgts/events', { query: params }),
        sync: (body: FgtsEsocialSyncRequest) =>
          client<{ data: FgtsEsocialSyncAccepted }>('/api/v1/fiscal/fgts/sync', {
            method: 'POST',
            body
          }),
        digital: {
          coverage: () =>
            client<{ data: FgtsDigitalCoverage }>('/api/v1/fiscal/fgts/digital/coverage'),
          readiness: (clientId: number) =>
            client<{ data: FgtsDigitalReadiness }>('/api/v1/fiscal/fgts/digital/readiness', {
              query: { client_id: clientId }
            }),
          runs: (params?: { client_id?: number, page?: number, per_page?: number }) =>
            client<{ data: FgtsDigitalRun[], current_page?: number, last_page?: number, total?: number }>(
              '/api/v1/fiscal/fgts/digital/runs',
              { query: params }
            ),
          sync: (body: { client_id: number, parameters?: Record<string, unknown> }) =>
            client<{ data: FgtsDigitalRun }>('/api/v1/fiscal/fgts/digital/sync', { method: 'POST', body }),
          syncNow: (body: { client_id: number, parameters?: Record<string, unknown> }) =>
            client<{ data: FgtsDigitalRun }>('/api/v1/fiscal/fgts/digital/sync-now', { method: 'POST', body }),
          preview: (body: { client_id: number, guide_type: string, parameters: Record<string, unknown> }) =>
            client<{ data: FgtsDigitalPreviewResponse }>('/api/v1/fiscal/fgts/digital/preview', { method: 'POST', body }),
          emit: (previewRunId: number, body: { preview_token: string, confirmation_phrase: string }) =>
            client<{ data: { run: FgtsDigitalRun, reused: boolean } }>(
              `/api/v1/fiscal/fgts/digital/previews/${previewRunId}/emit`,
              { method: 'POST', body }
            ),
          importSession: (body: { client_id: number, storage_state: Record<string, unknown> }) =>
            client<{ data: FgtsDigitalSessionDescriptor }>('/api/v1/fiscal/fgts/digital/sessions/import', {
              method: 'POST',
              body
            }),
          createRepresentation: (body: { client_id: number, valid_to: string, confirmed: boolean, profile_type?: string }) =>
            client<{ data: Record<string, unknown> }>('/api/v1/fiscal/fgts/digital/representations', {
              method: 'POST',
              body
            })
        }
      },
      mutations: {
        confirmTotp: (code: string) =>
          client<{ data: { confirmed: boolean, window_minutes?: number, seconds_remaining?: number } }>(
            '/api/v1/auth/confirm-totp',
            { method: 'POST', body: { code } }
          ),
        preflight: (body: Record<string, unknown>) =>
          client<{ data: FiscalMutationPreflight }>('/api/v1/fiscal/mutations/preflight', {
            method: 'POST',
            body
          }),
        execute: (body: Record<string, unknown>) =>
          client<{ data: FiscalMutationOperation }>('/api/v1/fiscal/mutations', {
            method: 'POST',
            body
          }),
        get: (id: number) =>
          client<{ data: FiscalMutationOperation }>(`/api/v1/fiscal/mutations/${id}`)
      },
      /**
       * Explorador de consultas manuais — GET só lê inventário local (sem SERPRO).
       * POST exige confirmed:true e despacha adapters existentes.
       */
      manualConsults: {
        inventory: (params?: {
          client_id?: number
          surface_key?: string
          module_key?: string
        }) =>
          client<{ data: ManualConsultInventory }>('/api/v1/fiscal/manual-consults', {
            query: params
          }),
        execute: (body: {
          action_id: string
          client_id: number
          confirmed: true
          params?: Record<string, unknown>
        }) =>
          client<{ data: ManualConsultExecuteResult }>('/api/v1/fiscal/manual-consults', {
            method: 'POST',
            body
          })
      }
    }
  }
}
