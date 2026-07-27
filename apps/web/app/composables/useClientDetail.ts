import type { Client, ClientCredential, Establishment } from '~/types/api'
import type { InjectionKey, Ref, ComputedRef } from 'vue'

export interface ClientDetailContext {
  clientId: ComputedRef<number>
  item: Ref<Client | null>
  credential: Ref<ClientCredential | null>
  loading: Ref<boolean>
  establishments: ComputedRef<Establishment[]>
  triggeringId: Ref<number | null>
  triggeredIds: Ref<number[]>
  canManageClients: ComputedRef<boolean>
  canManageCredentials: ComputedRef<boolean>
  canTriggerSync: ComputedRef<boolean>
  load: () => Promise<void>
  triggerSync: (establishment: Establishment) => Promise<void>
  onCredentialActivated: (value: ClientCredential) => void
  /** Abre o modal único de editar cadastro geral. */
  openClientEdit: () => void
}

export const clientDetailKey: InjectionKey<ClientDetailContext> = Symbol('clientDetail')

/** Contexto do detalhe de cliente (pai master-detail + NuxtPage). */
export function useClientDetail(): ClientDetailContext {
  const ctx = inject(clientDetailKey, null)
  if (!ctx) {
    throw new Error('useClientDetail() deve ser usado dentro de /clients/[id].')
  }
  return ctx
}
