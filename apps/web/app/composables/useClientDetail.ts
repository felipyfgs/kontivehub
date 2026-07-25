import type { Client, ClientCredential, Establishment } from '~/types/api'
import type { InjectionKey, Ref, ComputedRef } from 'vue'
import type { ClientDetailPanel, ClientDetailTab } from '~/utils/client-detail-tabs'
import { clientDetailHref, legacyClientPathToHref } from '~/utils/client-detail-tabs'

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
  sectionPath: (section?: string) => string
  goToTab: (tab: ClientDetailTab, panel?: ClientDetailPanel) => void
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

/** Path canônico do detalhe (`/clients/:id/:segment`). */
export function clientSectionPath(clientId: number | string, section?: string): string {
  if (!section || section === 'resumo' || section === 'index') {
    return clientDetailHref(clientId, 'cadastro')
  }
  return legacyClientPathToHref(clientId, section) || clientDetailHref(clientId)
}
