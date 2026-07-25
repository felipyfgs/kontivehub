/**
 * Chrome compartilhado do catálogo (/clients + /clients/dashboard):
 * permite ao dashboard registrar refresh na navbar do pai.
 */
import type { InjectionKey, Ref } from 'vue'

export type ClientsCatalogChrome = {
  loading: Ref<boolean>
  registerReload: (fn: (() => void | Promise<void>) | null) => void
  reload: () => void | Promise<void>
}

export const clientsCatalogChromeKey: InjectionKey<ClientsCatalogChrome> = Symbol('clientsCatalogChrome')

export function createClientsCatalogChrome(): ClientsCatalogChrome {
  const loading = ref(false)
  let reloadFn: (() => void | Promise<void>) | null = null

  return {
    loading,
    registerReload(fn) {
      reloadFn = fn
    },
    reload() {
      return reloadFn?.()
    }
  }
}

export function useClientsCatalogChrome() {
  return inject(clientsCatalogChromeKey, null)
}
