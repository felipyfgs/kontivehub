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

function lazyValue<T>(factory: () => T): () => T {
  let initialized = false
  let value: T

  return () => {
    if (!initialized) {
      value = factory()
      initialized = true
    }
    return value
  }
}

function createApiFacade(client: ApiClient, apiUrl: ApiUrl) {
  const auth = lazyValue(() => createAuthApi(client))
  const activationApi = lazyValue(() => createActivationApi(client))
  const onboardingApi = lazyValue(() => createOnboardingApi(client))
  const tenantApi = lazyValue(() => createTenantApi(client))
  const fiscalApi = lazyValue(() => createFiscalApi(client, apiUrl))
  const clientsApi = lazyValue(() => createClientsApi(client))
  const documentsApi = lazyValue(() => createDocumentsApi(client, apiUrl))
  const workApi = lazyValue(() => createWorkApi(client, apiUrl))
  const outboundApi = lazyValue(() => createOutboundApi(client))
  const operationsApi = lazyValue(() => createOperationsApi(client, apiUrl))
  const platformApi = lazyValue(() => createPlatformApi(client))
  const savedListFiltersApi = lazyValue(() => createSavedListFiltersApi(client))
  const communicationApi = lazyValue(() => createCommunicationApi(client, apiUrl))
  const assistantApi = lazyValue(() => createAssistantApi(client))

  return {
    get me() { return auth().me },
    get account() { return auth().account },
    get tenants() { return auth().tenants },
    get confirmPassword() { return auth().confirmPassword },
    get activations() { return activationApi().activations },
    get onboarding() { return onboardingApi().onboarding },
    get tenant() { return tenantApi().tenant },
    get fiscal() { return fiscalApi().fiscal },
    get clients() { return clientsApi().clients },
    get clientCategories() { return clientsApi().clientCategories },
    get cnpj() { return clientsApi().cnpj },
    get establishments() { return clientsApi().establishments },
    get contacts() { return clientsApi().contacts },
    get credentials() { return clientsApi().credentials },
    get documents() { return documentsApi().documents },
    get quarantine() { return operationsApi().quarantine },
    get tenantAutXml() { return tenantApi().tenantAutXml },
    get sync() { return operationsApi().sync },
    get cte() { return operationsApi().cte },
    get exports() { return operationsApi().exports },
    get operations() { return operationsApi().operations },
    get work() { return workApi().work },
    get outbound() { return outboundApi().outbound },
    get platform() { return platformApi().platform },
    get savedListFilters() { return savedListFiltersApi().savedListFilters },
    get communication() { return communicationApi().communication },
    get assistant() { return assistantApi().assistant }
  }
}

type ApiFacade = ReturnType<typeof createApiFacade>

const facadeByClient = new WeakMap<object, ApiFacade>()

/**
 * Fachada pública da API SPA — mesma árvore de chaves de topo.
 * Implementação por domínio em `composables/api/*`.
 * Cada cliente/fábrica é inicializado somente no primeiro acesso e a fachada é
 * reutilizada enquanto o Sanctum client permanecer o mesmo.
 */
export function useApi() {
  const client = useSanctumClient() as ApiClient
  const apiBase = useRuntimeConfig().public.apiBase.replace(/\/$/, '')
  const apiUrl: ApiUrl = (path: string) => `${apiBase}${path}`
  const key = client as unknown as object
  const existing = facadeByClient.get(key)
  if (existing) return existing

  const facade = createApiFacade(client, apiUrl)
  facadeByClient.set(key, facade)
  return facade
}
