/**
 * Lista paginada server-side no modelo visual do template customers.vue:
 * uma página por vez + UPagination (sem infinite scroll).
 */
import { computed, ref, type Ref } from 'vue'

export interface PagedTableBatch<T> {
  data: T[]
  currentPage?: number
  lastPage?: number
  total?: number | null
}

export interface LaravelPagePayload<T> {
  data?: T[]
  meta?: {
    current_page?: number
    last_page?: number
    total?: number
  }
  current_page?: number
  last_page?: number
  total?: number
}

/** Normaliza o LengthAwarePaginator do Laravel, no root ou em `meta`. */
export function laravelPageBatch<T>(payload: LaravelPagePayload<T>): PagedTableBatch<T> {
  const meta = payload.meta ?? payload
  const currentPage = Number(meta.current_page ?? 1)
  const lastPage = Number(meta.last_page ?? currentPage)

  return {
    data: payload.data ?? [],
    currentPage,
    lastPage,
    total: typeof meta.total === 'number' ? meta.total : null
  }
}

export interface PagedTableRequest {
  page: number
  pageSize: number
  signal: AbortSignal
}

export interface UsePagedTableOptions<T> {
  load: (request: PagedTableRequest) => Promise<PagedTableBatch<T>>
  /** Aceito e ignorado — identidade de linha é responsabilidade da tabela visual. */
  getKey?: (row: T) => string | number
  pageSize?: number
}

const LIST_PER_PAGE_ALLOWED = [10, 20, 50] as const

export function usePagedTable<T>(options: UsePagedTableOptions<T>) {
  const defaultPageSize = options.pageSize ?? 20
  const pageSize = ref(defaultPageSize)
  const rows = ref<T[]>([]) as Ref<T[]>
  const page = ref(1)
  const total = ref(0)
  const lastPage = ref(1)
  const pending = ref(false)
  const error = ref<unknown>(null)
  let seq = 0
  let controller: AbortController | null = null

  const pendingInitial = computed(() => pending.value && rows.value.length === 0)
  const hasMore = computed(() => page.value < lastPage.value)

  async function load(targetPage = page.value) {
    controller?.abort()
    controller = new AbortController()
    const my = ++seq
    pending.value = true
    error.value = null
    page.value = Math.max(1, targetPage)

    try {
      const batch = await options.load({
        page: page.value,
        pageSize: pageSize.value,
        signal: controller.signal
      })
      if (my !== seq) return

      rows.value = batch.data ?? []
      if (typeof batch.total === 'number') total.value = batch.total
      else total.value = rows.value.length

      if (typeof batch.lastPage === 'number') lastPage.value = Math.max(1, batch.lastPage)
      else if (typeof batch.total === 'number') {
        lastPage.value = Math.max(1, Math.ceil(batch.total / pageSize.value))
      } else {
        lastPage.value = 1
      }

      if (typeof batch.currentPage === 'number') page.value = batch.currentPage
    } catch (e) {
      if ((e as { name?: string })?.name === 'AbortError') return
      if (my !== seq) return
      error.value = e
    } finally {
      if (my === seq) pending.value = false
    }
  }

  async function resetAndLoad() {
    await load(1)
  }

  async function setPage(p: number) {
    await load(Math.max(1, Math.floor(p)))
  }

  /** Troca «N por página» — volta à página 1 e recarrega. */
  async function setPageSize(next: number) {
    const target = LIST_PER_PAGE_ALLOWED.includes(Number(next) as 10 | 20 | 50)
      ? Number(next)
      : defaultPageSize
    if (pageSize.value === target) return
    pageSize.value = target
    await load(1)
  }

  function reset() {
    controller?.abort()
    rows.value = []
    page.value = 1
    total.value = 0
    lastPage.value = 1
    error.value = null
    pending.value = false
  }

  async function retry() {
    await load(page.value)
  }

  return {
    rows,
    page,
    pageSize,
    total,
    lastPage,
    pending,
    pendingInitial,
    hasMore,
    error,
    load,
    resetAndLoad,
    setPage,
    setPageSize,
    reset,
    retry
  }
}
