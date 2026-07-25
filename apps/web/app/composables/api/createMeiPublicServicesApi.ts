import type {
  ConsultDasnHistoryInput,
  ConsultMeiDebtInput,
  DasnHistoryPayload,
  GenerateMeiDasInput,
  GenerateMeiDasPreflightInput,
  GenerateMeiDasResponse,
  MeiAutomationAttempt,
  MeiOperationQueuedResponse
} from '~/types/mei-public-services'
import type { FiscalMutationPreflight } from '~/types/api'
import type { ApiClient, ApiUrl } from './types'

export function createMeiPublicServicesApi(client: ApiClient, apiUrl: ApiUrl) {
  return {
    attempt: (attemptId: number) =>
      client<{ data: MeiAutomationAttempt }>(
        `/api/v1/fiscal/mei-automation/attempts/${attemptId}`
      ),
    artifactDownloadUrl: (attemptId: number, artifactId: string) =>
      apiUrl(
        `/api/v1/fiscal/mei-automation/attempts/${attemptId}/artifacts/${encodeURIComponent(artifactId)}/download`
      ),
    preflightDas: (body: GenerateMeiDasPreflightInput, idempotencyKey: string) =>
      client<{ data: FiscalMutationPreflight }>(
        '/api/v1/fiscal/simples-mei/pgmei/das/preflight',
        {
          method: 'POST',
          body,
          headers: { 'Idempotency-Key': idempotencyKey }
        }
      ),
    generateDas: (body: GenerateMeiDasInput, idempotencyKey: string) =>
      client<GenerateMeiDasResponse>(
        '/api/v1/fiscal/simples-mei/pgmei/das',
        {
          method: 'POST',
          body,
          headers: { 'Idempotency-Key': idempotencyKey }
        }
      ),
    consultDebt: (body: ConsultMeiDebtInput) =>
      client<MeiOperationQueuedResponse>(
        '/api/v1/fiscal/simples-mei/pgmei/consult',
        { method: 'POST', body }
      ),
    dasn: {
      history: (clientId: number, calendarYear?: number) =>
        client<{ data: DasnHistoryPayload }>(
          `/api/v1/fiscal/simples-mei/dasn-simei/clients/${clientId}/history`,
          { query: calendarYear ? { calendar_year: calendarYear } : undefined }
        ),
      consult: (body: ConsultDasnHistoryInput) =>
        client<MeiOperationQueuedResponse>(
          '/api/v1/fiscal/simples-mei/dasn-simei/consult',
          { method: 'POST', body }
        )
    }
  }
}
