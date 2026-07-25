/**
 * Paginação offset local (slice) — listas já carregadas em memória.
 * Contrato visual: 10/20/50 + página clampada (mesmo do ShellTableFooter).
 */
import type { ComputedRef, Ref } from 'vue'
import {
  clampListTablePage,
  listTablePageCount,
  normalizeListTablePerPage,
  type ListTablePerPage
} from '~/utils/table-ui'

export function useLocalTablePagination<T>(
  source: Ref<readonly T[]> | ComputedRef<readonly T[]> | Ref<T[]> | ComputedRef<T[]>,
  options?: { perPage?: ListTablePerPage }
) {
  const page = ref(1)
  const perPage = ref<ListTablePerPage>(normalizeListTablePerPage(options?.perPage, 20))

  const total = computed(() => source.value.length)
  const lastPage = computed(() => listTablePageCount(total.value, perPage.value))

  const rows = computed(() => {
    const list = source.value
    const size = perPage.value
    const current = clampListTablePage(page.value, lastPage.value)
    const start = (current - 1) * size
    return list.slice(start, start + size)
  })

  watch(total, () => {
    page.value = clampListTablePage(page.value, lastPage.value)
  })

  function setPage(next: number) {
    page.value = clampListTablePage(next, lastPage.value)
  }

  function setPerPage(next: number) {
    const target = normalizeListTablePerPage(next, perPage.value)
    if (perPage.value === target) return
    perPage.value = target
    page.value = 1
  }

  function resetPage() {
    page.value = 1
  }

  return {
    page,
    perPage,
    total,
    lastPage,
    rows,
    setPage,
    setPerPage,
    resetPage
  }
}
