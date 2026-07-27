import type { ApiClient, ApiUrl } from './api/types'

import { createAuthApi } from './api/createAuthApi'
import { createActivationApi } from './api/createActivationApi'
import { createOnboardingApi } from './api/createOnboardingApi'
import { createTenantApi } from './api/createTenantApi'
import { createFiscalApi } from './api/createFiscalApi'
import { createClientsApi } from './api/createClientsApi'
import { createDocumentsApi } from './api/createDocumentsApi'
import { createWorkApi } from './api/createWorkApi'
import { createOutboundApi } from './api/createOutboundApi'
import { createOperationsApi } from './api/createOperationsApi'
import { createPlatformApi } from './api/createPlatformApi'
import { createSavedListFiltersApi } from './api/createSavedListFiltersApi'
import { createCommunicationApi } from './api/createCommunicationApi'
import { createAssistantApi } from './api/createAssistantApi'

export type { ClientListParams, DocumentListParams, InboxListParams } from './api/types'

/**
 * Fachada pública da API SPA — mesma árvore de chaves de topo.
 * Implementação por domínio em `composables/api/*`.
 */
export function useApi() {
  const client = useSanctumClient() as ApiClient
  const apiBase = useRuntimeConfig().public.apiBase.replace(/\/$/, '')
  const apiUrl: ApiUrl = (path: string) => `${apiBase}${path}`

  const auth = createAuthApi(client)
  const activationApi = createActivationApi(client)
  const onboardingApi = createOnboardingApi(client)
  const tenantApi = createTenantApi(client)
  const fiscalApi = createFiscalApi(client, apiUrl)
  const clientsApi = createClientsApi(client)
  const documentsApi = createDocumentsApi(client, apiUrl)
  const workApi = createWorkApi(client, apiUrl)
  const outboundApi = createOutboundApi(client)
  const operationsApi = createOperationsApi(client, apiUrl)
  const platformApi = createPlatformApi(client)
  const savedListFiltersApi = createSavedListFiltersApi(client)
  const communicationApi = createCommunicationApi(client, apiUrl)
  const assistantApi = createAssistantApi(client)

  // Ordem de chaves idêntica à fachada monólito (acesso por nome; ordem estável).
  return {
    me: auth.me,
    account: auth.account,
    tenants: auth.tenants,
    confirmPassword: auth.confirmPassword,
    activations: activationApi.activations,
    onboarding: onboardingApi.onboarding,
    tenant: tenantApi.tenant,
    fiscal: fiscalApi.fiscal,
    clients: clientsApi.clients,
    clientCategories: clientsApi.clientCategories,
    cnpj: clientsApi.cnpj,
    establishments: clientsApi.establishments,
    contacts: clientsApi.contacts,
    credentials: clientsApi.credentials,
    documents: documentsApi.documents,
    quarantine: operationsApi.quarantine,
    tenantAutXml: tenantApi.tenantAutXml,
    sync: operationsApi.sync,
    cte: operationsApi.cte,
    exports: operationsApi.exports,
    operations: operationsApi.operations,
    work: workApi.work,
    outbound: outboundApi.outbound,
    platform: platformApi.platform,
    savedListFilters: savedListFiltersApi.savedListFilters,
    communication: communicationApi.communication,
    assistant: assistantApi.assistant
  }
}
