/**
 * Convenções de estados assíncronos do painel (tarefa 2.10).
 * Não cria wrapper de página — helpers tipados para loading/vazio/erro/refresh.
 */

export type AsyncLoadState = 'idle' | 'loading' | 'success' | 'empty' | 'error'

export interface AsyncUiSnapshot<T> {
  state: AsyncLoadState
  data: T | null
  /** Último payload válido (preservado em falha de refresh). */
  lastGood: T | null
  errorMessage: string | null
  /** true quando a falha atual ainda exibe lastGood. */
  stale: boolean
}

export function initialAsyncUi<T>(data: T | null = null): AsyncUiSnapshot<T> {
  return {
    state: data ? 'success' : 'idle',
    data,
    lastGood: data,
    errorMessage: null,
    stale: false
  }
}

/** Início de carga (primeira ou refresh). */
export function markLoading<T>(snap: AsyncUiSnapshot<T>): AsyncUiSnapshot<T> {
  return {
    ...snap,
    state: 'loading',
    errorMessage: null,
    stale: false
  }
}

/** Sucesso com dados (ou vazio). */
export function markSuccess<T>(
  snap: AsyncUiSnapshot<T>,
  data: T,
  isEmpty: (value: T) => boolean = defaultIsEmpty
): AsyncUiSnapshot<T> {
  const empty = isEmpty(data)
  return {
    state: empty ? 'empty' : 'success',
    data,
    lastGood: data,
    errorMessage: null,
    stale: false
  }
}

/**
 * Falha: se houver lastGood, preserva dados e marca stale;
 * se for carga inicial sem dados, state=error sem inventar conteúdo.
 */
export function markFailure<T>(
  snap: AsyncUiSnapshot<T>,
  message: string
): AsyncUiSnapshot<T> {
  const hasGood = snap.lastGood != null
  return {
    state: hasGood ? snap.state === 'empty' ? 'empty' : 'success' : 'error',
    data: hasGood ? snap.lastGood : null,
    lastGood: snap.lastGood,
    errorMessage: message,
    stale: hasGood
  }
}

function defaultIsEmpty<T>(value: T): boolean {
  if (value == null) return true
  if (Array.isArray(value)) return value.length === 0
  if (typeof value === 'object' && value !== null && 'data' in value) {
    const inner = (value as { data: unknown }).data
    if (Array.isArray(inner)) return inner.length === 0
  }
  return false
}

/** Rótulos padrão pt-BR para UI (não inventar métricas). */
export const ASYNC_UI_COPY = Object.freeze({
  loading: 'Carregando…',
  empty: 'Nenhum registro neste recorte.',
  errorInitial: 'Não foi possível carregar os dados.',
  errorRefresh: 'Falha ao atualizar. Exibindo a última carga válida.',
  forbidden: 'Você não tem permissão para esta ação ou recurso.',
  conflict: 'Os dados foram alterados em outra sessão. Recarregue antes de continuar.',
  validation: 'Corrija os campos destacados e tente novamente.',
  retry: 'Tentar novamente'
} as const)

/** Códigos HTTP → mensagem canônica. */
export function asyncMessageForStatus(status: number | undefined, fallback?: string): string {
  if (status === 403) return ASYNC_UI_COPY.forbidden
  if (status === 409) return ASYNC_UI_COPY.conflict
  if (status === 422) return ASYNC_UI_COPY.validation
  if (status === 404) return 'Recurso não encontrado neste escritório.'
  return fallback || ASYNC_UI_COPY.errorInitial
}
