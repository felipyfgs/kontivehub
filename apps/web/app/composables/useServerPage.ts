/**
 * Paginação server-side + filtros locais da instância da página.
 * Nunca inventa dados: o caller preenche rows a partir da API.
 */
export interface ServerPageState {
  page: number
  perPage: number
  total: number
  lastPage: number
  q: string
  situation: string
  clientId: string
  competence: string
}

function asPositiveInt(value: unknown, fallback: number): number {
  const n = Number(value)
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : fallback
}

export function useServerPage(defaults?: Partial<ServerPageState>) {
  const route = useRoute()
  const router = useRouter()

  const page = ref(asPositiveInt(defaults?.page, 1))
  const perPage = ref(defaults?.perPage ?? 20)
  const total = ref(0)
  const lastPage = ref(1)
  const q = ref(String(defaults?.q || ''))
  const situation = ref(String(defaults?.situation || 'all'))
  const clientId = ref(String(defaults?.clientId || ''))
  const competence = ref(String(defaults?.competence || ''))

  const loading = ref(false)
  const loadError = ref<string | null>(null)

  function applyMeta(meta?: {
    current_page?: number
    last_page?: number
    total?: number
    per_page?: number
  } | null) {
    if (!meta) return
    if (typeof meta.current_page === 'number') page.value = meta.current_page
    if (typeof meta.last_page === 'number') lastPage.value = meta.last_page
    if (typeof meta.total === 'number') total.value = meta.total
    if (typeof meta.per_page === 'number') perPage.value = meta.per_page
  }

  /** Laravel LengthAwarePaginator no root ou em meta. */
  function applyPaginator(payload: Record<string, unknown> | null | undefined) {
    if (!payload) return
    const meta = (payload.meta as Record<string, unknown> | undefined) || payload
    applyMeta({
      current_page: Number(meta.current_page ?? page.value),
      last_page: Number(meta.last_page ?? lastPage.value),
      total: Number(meta.total ?? total.value),
      per_page: Number(meta.per_page ?? perPage.value)
    })
  }

  async function syncUrl() {
    if (Object.keys(route.query).length > 0) {
      await router.replace({ path: route.path })
    }
  }

  function resetPage() {
    page.value = 1
  }

  return {
    page,
    perPage,
    total,
    lastPage,
    q,
    situation,
    clientId,
    competence,
    loading,
    loadError,
    applyMeta,
    applyPaginator,
    syncUrl,
    resetPage
  }
}
