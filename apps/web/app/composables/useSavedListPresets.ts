/**
 * CRUD + aplicar presets de filtros de lista por surface.
 * Nunca envia office_id — escopo só no servidor (CurrentOffice).
 */
import type { MaybeRefOrGetter } from 'vue'
import type {
  SavedFilterVisibility,
  SavedListFilter,
  SavedListFilterPayload
} from '~/types/saved-list-filters'
import { useApi } from '~/composables/useApi'
import { useDashboard } from '~/composables/useDashboard'
import { apiErrorMessage } from '~/utils/api-error'
import { canCreateExport } from '~/utils/permissions'

export interface UseSavedListPresetsOptions {
  /** Surface estável (ex. clients.index). Vazio desliga. */
  surface: MaybeRefOrGetter<string | null | undefined>
  /** VIEWER não publica (default: ADMIN|OPERATOR via canCreateExport). */
  canShare?: MaybeRefOrGetter<boolean | undefined>
  /** sessionEpoch / troca de lista — limpa cache de presets. */
  resetKey?: MaybeRefOrGetter<string | number | null | undefined>
  /** Serializa o estado aplicado atual para a API. */
  getPayload: () => SavedListFilterPayload
  /** True se o recorte atual tem conteúdo útil para salvar. */
  canSave: () => boolean
  /** Aplica payload do preset (host hidrata estado + recarrega). */
  onApply: (payload: SavedListFilterPayload, filter: SavedListFilter) => void
}

export function useSavedListPresets(options: UseSavedListPresetsOptions) {
  const api = useApi()
  const toast = useToast()
  const { me } = useDashboard()

  const surfaceValue = computed(() => {
    const raw = toValue(options.surface)
    return raw && String(raw).trim() ? String(raw).trim() : null
  })

  const enabled = computed(() => Boolean(surfaceValue.value))

  const canShare = computed(() => {
    const override = toValue(options.canShare)
    if (typeof override === 'boolean') return override
    return canCreateExport(me.value)
  })

  const presets = ref<SavedListFilter[]>([])
  const presetsLoaded = ref(false)
  const presetsLoading = ref(false)
  const saveOpen = ref(false)
  const manageOpen = ref(false)
  const saveLoading = ref(false)
  const saveError = ref<string | null>(null)
  const manageError = ref<string | null>(null)
  const actingId = ref<number | null>(null)

  const canSavePreset = computed(() => enabled.value && options.canSave())

  function clearPresetCache() {
    presets.value = []
    presetsLoaded.value = false
    presetsLoading.value = false
    saveOpen.value = false
    manageOpen.value = false
    saveError.value = null
    manageError.value = null
    actingId.value = null
  }

  watch(
    () => toValue(options.resetKey),
    (next, prev) => {
      if (prev === undefined) return
      if (next === prev) return
      clearPresetCache()
    }
  )

  watch(surfaceValue, (next, prev) => {
    if (prev === undefined) return
    if (next === prev) return
    clearPresetCache()
  })

  async function loadPresets(force = false) {
    if (!enabled.value || !surfaceValue.value) return
    if (presetsLoading.value) return
    if (presetsLoaded.value && !force) return

    presetsLoading.value = true
    manageError.value = null
    try {
      const res = await api.savedListFilters.list({ surface: surfaceValue.value })
      presets.value = Array.isArray(res?.data) ? res.data : []
      presetsLoaded.value = true
    } catch (error) {
      manageError.value = apiErrorMessage(error, 'Falha ao carregar filtros salvos.')
      presets.value = []
    } finally {
      presetsLoading.value = false
    }
  }

  function onSavedMenuOpen() {
    void loadPresets()
  }

  function applyPreset(filter: SavedListFilter) {
    if (!surfaceValue.value || filter.surface !== surfaceValue.value) {
      toast.add({
        title: 'Filtro incompatível',
        description: 'Este preset não pertence a esta lista.',
        color: 'warning'
      })
      return
    }
    options.onApply(filter.payload ?? { schema_version: 1 }, filter)
  }

  async function onSaveConfirm(payload: { name: string, share: boolean }) {
    if (!surfaceValue.value) return
    saveLoading.value = true
    saveError.value = null
    try {
      const res = await api.savedListFilters.create({
        surface: surfaceValue.value,
        name: payload.name,
        visibility: payload.share ? 'office' : 'personal',
        payload: options.getPayload(),
        schema_version: 1
      })
      if (res?.data) {
        presets.value = [
          res.data,
          ...presets.value.filter(item => item.id !== res.data.id)
        ]
        presetsLoaded.value = true
      } else {
        await loadPresets(true)
      }
      saveOpen.value = false
      toast.add({
        title: 'Filtro salvo',
        description: payload.share
          ? 'Compartilhado com o escritório.'
          : 'Disponível em Meus filtros.',
        color: 'success'
      })
    } catch (error) {
      saveError.value = apiErrorMessage(error, 'Não foi possível salvar o filtro.')
    } finally {
      saveLoading.value = false
    }
  }

  async function onRename(payload: { id: number, name: string }) {
    actingId.value = payload.id
    manageError.value = null
    try {
      const res = await api.savedListFilters.update(payload.id, { name: payload.name })
      if (res?.data) {
        presets.value = presets.value.map(item =>
          item.id === payload.id ? res.data : item
        )
      }
    } catch (error) {
      manageError.value = apiErrorMessage(error, 'Falha ao renomear.')
    } finally {
      actingId.value = null
    }
  }

  async function onToggleShare(payload: { id: number, visibility: SavedFilterVisibility }) {
    actingId.value = payload.id
    manageError.value = null
    try {
      const res = await api.savedListFilters.update(payload.id, {
        visibility: payload.visibility
      })
      if (res?.data) {
        presets.value = presets.value.map(item =>
          item.id === payload.id ? res.data : item
        )
      }
    } catch (error) {
      manageError.value = apiErrorMessage(error, 'Falha ao alterar compartilhamento.')
    } finally {
      actingId.value = null
    }
  }

  async function onDeletePreset(payload: { id: number }) {
    actingId.value = payload.id
    manageError.value = null
    try {
      await api.savedListFilters.delete(payload.id)
      presets.value = presets.value.filter(item => item.id !== payload.id)
    } catch (error) {
      manageError.value = apiErrorMessage(error, 'Falha ao excluir filtro.')
    } finally {
      actingId.value = null
    }
  }

  function openManage() {
    manageOpen.value = true
    void loadPresets(true)
  }

  function openSave() {
    saveOpen.value = true
  }

  return {
    enabled,
    canShare,
    canSavePreset,
    presets,
    presetsLoaded,
    presetsLoading,
    saveOpen,
    manageOpen,
    saveLoading,
    saveError,
    manageError,
    actingId,
    clearPresetCache,
    loadPresets,
    onSavedMenuOpen,
    applyPreset,
    onSaveConfirm,
    onRename,
    onToggleShare,
    onDeletePreset,
    openManage,
    openSave
  }
}
