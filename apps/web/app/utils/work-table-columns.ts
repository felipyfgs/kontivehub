/**
 * Contrato de larguras das listas Work (Cliente | Processo | Tarefa).
 * Usar em `column.meta.class` nas coleções Work.
 *
 * Tokens (table-fixed / ShellDataTable):
 * - primary: absorve espaço restante (ellipsis)
 * - status / due / count / progress / assignee: larguras fixas alinhadas entre superfícies
 */
export const WORK_TABLE_COL = Object.freeze({
  /** Checkbox de seleção (lista de tarefas). */
  select: Object.freeze({ th: 'w-10 min-w-10', td: 'w-10 min-w-10' }),
  /** Só expand. */
  expand: Object.freeze({ th: 'w-10 min-w-10', td: 'w-10 min-w-10' }),
  /** Expand + checkbox de marcar todas do grupo. */
  expandWithSelect: Object.freeze({ th: 'w-16 min-w-16', td: 'w-16 min-w-16' }),
  /** Identidade (cliente / processo / tarefa). */
  primary: Object.freeze({ th: 'w-full max-w-0 min-w-48', td: 'w-full max-w-0 min-w-48' }),
  /** Status / situação. */
  status: Object.freeze({ th: 'w-36 min-w-32', td: 'w-36 min-w-32' }),
  /** Prazo / próximo prazo. */
  due: Object.freeze({ th: 'w-28 min-w-24', td: 'w-28 min-w-24' }),
  /** Contagens curtas (processos, clientes, instâncias). */
  count: Object.freeze({ th: 'w-24 min-w-20', td: 'w-24 min-w-20' }),
  /** Contagem um pouco maior (tarefas abertas). */
  countWide: Object.freeze({ th: 'w-28 min-w-24', td: 'w-28 min-w-24' }),
  /** Barra de progresso. */
  progress: Object.freeze({ th: 'w-36 min-w-32', td: 'w-36 min-w-32' }),
  /** Contexto secundário (cliente/processo na lista de tarefas). */
  secondary: Object.freeze({ th: 'w-48 min-w-40', td: 'w-48 min-w-40' }),
  /** Responsável. */
  assignee: Object.freeze({ th: 'w-36 min-w-28', td: 'w-36 min-w-28' }),
  /** Ações ícone. */
  actions: Object.freeze({ th: 'w-14 min-w-12', td: 'w-14 min-w-12' })
})
