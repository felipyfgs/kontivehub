import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

describe('work-surface-composition', () => {
  it('fila não auto-seleciona e deep-link não limpa path fora da página', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    expect(workspace).not.toContain('suppressAutoSelect')
    expect(workspace).not.toContain('await select(items.value[0]')
    expect(workspace).toContain('onEntityLevel')
    expect(workspace).toContain('void loadDetail(id)')
    // Deep-link fora da página: não limpa path ao recarregar a fila
    expect(workspace).not.toMatch(/if \(current && !items\.value\.some[\s\S]*clearTask\(\)/)
  })

  it('entity toggle emite e o pai navega uma vez', () => {
    const toggle = source('app/components/work/WorkEntityLevelToggle.vue')
    const processes = source('app/pages/work/processes/index.vue')
    const queueChrome = source('app/components/work/WorkQueueChrome.vue')
    const queue = source('app/components/work/WorkQueueWorkspace.vue')
    expect(toggle).not.toContain('navigateTo')
    expect(processes).toContain('@update:model-value="onEntityLevel"')
    expect(processes).toContain('navigateEntityLevel')
    expect(queueChrome).toContain('@update:model-value="emit(\'update:entityLevel\', $event)"')
    expect(queue).toContain('@update:entity-level="onEntityLevel"')
  })

  it('renderiza uma única definição compartilhada do chrome nas três visões', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const chrome = source('app/components/work/WorkQueueChrome.vue')

    expect(workspace.match(/<WorkQueueChrome/g)).toHaveLength(1)
    expect(workspace).toContain('flex min-h-0 min-w-0 w-full flex-1 flex-col')
    expect(workspace).not.toContain('<UDashboardNavbar')
    expect(workspace).not.toContain('<UDashboardToolbar')
    expect(chrome.match(/<ShellPageNavbar/g)).toHaveLength(1)
    expect(chrome).not.toContain('<UDashboardNavbar')
    expect(chrome).not.toContain('<UDashboardSidebarCollapse')
    expect(chrome.match(/<UDashboardToolbar/g)).toHaveLength(1)
    expect(chrome.match(/data-testid="work-queue-view-toggle"/g)).toHaveLength(1)
    expect(chrome.match(/<WorkEntityLevelToggle/g)).toHaveLength(1)
    expect(chrome).toContain('v-if="showDetailToggle"')
    expect(chrome).toContain('flex w-full min-w-0 flex-wrap')
    expect(chrome).toContain('ml-auto flex min-w-0 max-w-full flex-wrap')
    expect(chrome).toContain('overflow-x-visible')
    expect(chrome).not.toContain('min-w-max')
    expect(chrome).not.toContain('overflow-x-auto')
    expect(workspace).toContain('v-if="isLista && canBulk && selectedCount > 0"')
  })

  it('fila aceita teclado e todas as visões restauram o originador ao fechar detalhe', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const item = source('app/components/work/WorkQueueListItem.vue')

    expect(item).toContain('@keydown.enter.prevent="select"')
    expect(item).toContain('@keydown.space.prevent="select"')
    expect(item).toContain('defineExpose({ el: rootEl, focus })')
    expect(workspace).toContain('focusQueueItem')
    expect(workspace).toContain('itemRefs.value[id]?.focus()')
    expect(workspace).toContain('document.activeElement')
    expect(workspace).toContain('restoreWorkSelectionFocus(origin')
    expect(workspace).toContain('() => focusQueueItem(focusId)')
  })

  it('Fila mantém last-good visível durante refresh e falha recuperável', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const loadQueue = workspace.split('async function loadQueue()')[1]?.split('async function loadDetail')[0] ?? ''
    const fila = workspace.split('id="work-queue-list"')[1]?.split('</UDashboardPanel>')[0] ?? ''

    expect(fila).toContain('v-if="loadError && !items.length"')
    expect(fila).toContain('v-else-if="loading && !items.length"')
    expect(fila).toContain('data-testid="work-queue-stale-error"')
    expect(fila).toContain('data-testid="work-queue-stale-retry"')
    expect(fila).toContain('data-testid="work-queue-refreshing"')
    expect(fila).toContain('role="listbox"')
    expect(fila.indexOf('work-queue-stale-error')).toBeLessThan(fila.indexOf('role="listbox"'))
    expect(loadQueue).not.toContain('items.value = []')
  })

  it('Lista usa preset dashboard, id estável e cards mobile canônicos', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const dataTable = source('app/components/shell/DataTable.vue')
    const list = workspace.split('<ShellDataTable')[1]?.split('</ShellDataTable>')[0] ?? ''

    expect(list).not.toContain('ui-preset="monitoring-compact"')
    expect(dataTable).toContain('uiPreset: \'dashboard\'')
    expect(list).toContain(':get-row-id="task => String(task.id)"')
    expect(list).toContain('mobile-cards-test-id="work-queue-mobile-cards"')
    expect(list).toContain(':column-labels="{')
    expect(list).toContain('primary-column-id="title"')
    expect(list).toContain('status-column-id="status"')
    expect(list).toContain(':summary-column-ids="[\'effective_due_date\', \'client_name\', \'assignee_name\']"')
    expect(list).toContain(':selection-enabled="canExecute || canAdmin"')
  })

  it('Tarefas distingue vazio inicial, filtrado e erro com retry', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')

    expect(workspace).toContain('hasQueueResultFilters')
    expect(workspace).toContain('queueEmptyKind')
    expect(workspace).toContain('queueEmptyTitle')
    expect(workspace).toContain('queueEmptyDescription')
    expect(workspace).toContain(':empty-kind="queueEmptyKind"')
    expect(workspace).toContain(':error="loadError"')
    expect(workspace).toContain('@retry="loadQueue"')
    expect(workspace).toContain('work-queue-empty-clear')
    expect(workspace).toContain('@click="onQueueClear"')
    expect(workspace).toContain('tab: isKanban.value ? \'todas\' : \'open\'')
  })

  it('Lista preserva sorting e paginação server-side por whitelist', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const filters = source('app/composables/useWorkQueueFilters.ts')

    expect(workspace).toContain('const allowed = new Set([\'title\', \'status\', \'effective_due_date\', \'client_name\', \'assignee_name\'])')
    expect(workspace).toContain(':manual-sorting="true"')
    expect(workspace).toContain('@update:page="onListPage"')
    expect(workspace).toContain('@update:items-per-page="onListPerPage"')
    expect(workspace).toContain('@update:sorting="onListSortingUpdate"')
    expect(filters).toContain('page: f.page')
    expect(filters).toContain('per_page: f.per_page')
    expect(filters).toContain('params.sort = f.sort')
    expect(filters).toContain('params.direction = f.direction || \'asc\'')
  })

  it('remove redundâncias: CTA Rotinas/lote, Acessos rápidos e ações duplicadas no navbar do detalhe', () => {
    const processes = source('app/pages/work/processes/index.vue')
    const dashboard = source('app/pages/work/index.vue')
    const detail = source('app/components/work/WorkTaskDetailPanel.vue')
    expect(processes).not.toContain('Rotinas / lote')
    expect(dashboard).not.toContain('work-dashboard-quick-links')
    expect(dashboard).not.toContain('Acessos rápidos')
    expect(detail).toContain('runAction(\'start\')')
    // ações ficam no corpo, não no #right da navbar
    const navbar = detail.split('data-testid="work-task-detail-navbar"')[1]?.split('</UDashboardNavbar>')[0] ?? ''
    expect(navbar).not.toContain('runAction(')
  })

  it('detalhe tem uma ação por transição, um fechar no slideover e comentários sem card-in-card', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const detail = source('app/components/work/WorkTaskDetailPanel.vue')
    const installedSlideover = source('node_modules/@nuxt/ui/dist/runtime/components/Slideover.vue')
    const template = detail.split('<template>')[1] ?? ''

    for (const action of ['start', 'complete', 'resume', 'claim', 'block']) {
      expect(template.match(new RegExp(`runAction\\('${action}'\\)`, 'g'))).toHaveLength(1)
    }
    expect(workspace).toContain('<template #content>')
    expect(workspace).toContain('title="Tarefa"')
    expect(workspace).toContain(':close="false"')
    expect(installedSlideover).toContain('<DialogTitle v-else-if="!!slots.content">')
    expect(installedSlideover).toMatch(/<DialogTitle v-else-if="!!slots\.content">[\s\S]*?\{\{ props\.title \}\}/)
    expect(detail.match(/data-testid="work-task-detail-close"/g)).toHaveLength(1)
    expect(detail).not.toContain('<UCard')
    expect(detail).toContain('data-testid="work-task-detail-comments"')
    expect(detail).toContain('<ol class="space-y-3">')
    expect(detail).not.toContain('class="rounded-md border border-default p-2 text-sm"')
  })

  it('calendário distingue erro do dia e usa botão semântico', () => {
    const calendar = source('app/pages/work/calendar.vue')
    expect(calendar).toContain('work-calendar-day-error')
    expect(calendar).toContain('dayError')
    expect(calendar).toContain(':aria-label="`Abrir tarefa ${item.title}`"')
    // um único collapse no painel principal; rail sem collapse duplicado
    expect(calendar).toContain('UDashboardSidebarCollapse')
    expect(calendar).toContain('work-calendar-rail-navbar')
    expect(calendar.split('UDashboardSidebarCollapse').length - 1).toBe(1)
    expect(calendar).toContain('workCalendarLoadPlan(previous, next)')
  })

  it('processos modo Cliente: árvore inline compacta com prefetch e bulk só nos filhos', () => {
    const processes = source('app/pages/work/processes/index.vue')
    expect(processes).toContain('work-process-group-tree')
    expect(processes).toContain('work-process-group-tree-table')
    expect(processes).toContain('work-process-task-table')
    expect(processes).toContain('work-process-group-children-pagination')
    expect(processes).toContain('prefetchGroupChildren')
    expect(processes).toContain('selection-enabled="false"')
    expect(processes).toContain('work-process-child-select')
    expect(processes).toContain('work-process-group-select')
    expect(processes).toContain(':mobile-cards="false"')
    expect(processes).not.toContain('Processos do cliente')
    expect(processes).not.toContain('Selecionar empresas')
    expect(processes).not.toContain('Selecionar processos')
    expect(processes).not.toContain('USlideover')
    expect(processes).not.toContain('work-processes-slideover-bulk')
    expect(processes).not.toContain('value: \'ARQUIVADO\'')
    expect(processes).not.toContain(':aria-level=')
    expect(processes).toContain(':aria-label="`Processos do grupo ${row.original.label}`"')
    expect(processes).toContain(':aria-label="`Tarefas de ${process.title}`"')
  })

  it('árvore de processos tem cabeçalhos semânticos, hierarquia textual e mobile sem overflow artificial', () => {
    const processes = source('app/pages/work/processes/index.vue')
    const tree = processes.split('<template #expanded="{ row }">')[1]?.split('<template #empty>')[0] ?? ''

    expect(tree).toContain('<table')
    expect(tree).toContain('<thead')
    expect(tree).toContain('<th scope="col"')
    expect(tree).toContain('scope="row"')
    expect(tree).toContain('Processos e empresas materializados neste grupo')
    expect(tree).toContain('Departamento')
    expect(tree).toContain('Responsável')
    expect(tree).toContain('Prazo e responsável')
    expect(tree).toContain('Status da tarefa')
    expect(tree).toContain('Sem departamento')
    expect(tree).toContain('Sem responsável')
    expect(tree).toContain('md:hidden')
    expect(tree).toContain('md:table-row')
    expect(tree).not.toContain('<UCard')
    expect(tree).not.toContain('rounded-full')
    expect(tree).not.toContain('WORK_GROUP_CHILD_ROW_GRID')
    expect(tree).not.toContain('overflow-x')
    expect(tree).not.toMatch(/min-w-\[[^\]]+\]/)
  })

  it('árvore mantém estados por ramo, paginação e seleção materializada', () => {
    const processes = source('app/pages/work/processes/index.vue')

    expect(processes).toContain('work-process-group-children-loading')
    expect(processes).toContain('work-process-group-children-error')
    expect(processes).toContain('work-process-group-children-retry')
    expect(processes).toContain('retryGroupChildren(row.original)')
    expect(processes).toContain('work-process-group-children-pagination')
    expect(processes).toContain('materialisedProcessCache')
    expect(processes).toContain('selectedMaterialisedBulkItems')
    expect(processes).toContain('work-process-child-select')
    expect(processes).toContain('work-process-task-select')
    expect(processes).toContain('WorkTaskStatusSelect')
  })

  it('refresh de ramo preserva last-good, contexto expandido e retry inline', () => {
    const processes = source('app/pages/work/processes/index.vue')
    const loader = processes
      .split('async function loadGroupChildren')[1]
      ?.split('async function prefetchGroupChildren')[0] ?? ''
    const catchBlock = loader.split('} catch (e) {')[1] ?? ''
    const tree = processes
      .split('<template #expanded="{ row }">')[1]
      ?.split('<template #empty>')[0] ?? ''

    expect(loader).toContain('const hasLastGood = existing.processes.length > 0')
    expect(loader).toContain('...(hasLastGood ? {} : { page })')
    expect(catchBlock).toContain('status: \'error\'')
    expect(catchBlock).toContain('error: message')
    expect(catchBlock).not.toContain('processes: []')
    expect(catchBlock).not.toContain('total: 0')
    expect(tree).toContain('status === \'loading\'')
    expect(tree).toContain('&& !groupCacheEntry(row.original.key).processes.length')
    expect(tree).toContain('work-process-group-children-refreshing')
    expect(tree).toContain('work-process-group-children-stale-error')
    expect(tree).toContain('work-process-group-children-stale-retry')
    expect(tree).toContain('Dados anteriores mantidos.')
    expect(tree).toContain('role="status"')
    expect(tree).toContain('role="alert"')
    expect(tree).toContain('retryGroupChildren(row.original)')
    expect(processes).toContain('expandedChildProcessIds')
  })

  it('páginas comuns Work usam ShellPagePanel', () => {
    expect(source('app/pages/work/index.vue')).toContain('ShellPagePanel')
    expect(source('app/pages/work/processes/index.vue')).toContain('ShellPagePanel')
    expect(source('app/pages/work/templates/index.vue')).toContain('ShellPagePanel')
  })

  it('detalhe do processo usa uma única casca settings e retry no erro principal', () => {
    const detail = source('app/pages/work/processes/[id].vue')
    expect(detail.match(/<ShellSettingsShell/g)).toHaveLength(1)
    expect(detail).not.toContain('<UDashboardPanel')
    expect(detail).not.toContain('<UDashboardNavbar')
    expect(detail).toContain(':back-to="backToProcesses"')
    expect(detail).toContain('data-testid="work-process-retry"')
    expect(detail).toContain('@click="load"')
    expect(detail).toContain('work-process-summary-definition')
    expect(detail).toContain('<dl')
    expect(detail).toContain('<dt')
    expect(detail).toContain('<dd')
    expect(detail).not.toContain('<ShellSectionCard')
  })

  it('Rotinas usa formulários Zod nomeados, estados de erro e descarte confirmado', () => {
    const templates = source('app/pages/work/templates/index.vue')
    const schemas = source('app/utils/work-routine-forms.ts')
    expect(schemas).toContain('import * as z from \'zod\'')
    expect(templates).toContain(':schema="workTemplateFormSchema"')
    expect(templates).toContain(':schema="workGenerationFormSchema"')
    expect(templates).toContain('name="name"')
    expect(templates).toContain(':name="`tasks.${index}.title`"')
    expect(templates).toContain('name="competence"')
    expect(templates).toContain('autofocus')
    expect(templates).toContain('editorDiscardOpen')
    expect(templates).toContain('generationDiscardOpen')
    expect(templates).toContain('work-template-catalog-error')
    expect(templates).toContain(':error="templates.length ? null : templatesError"')
    expect(templates).toContain('@retry="loadTemplates"')
    expect(templates).toContain('createWorkFormSubmissionGuard')
    expect(templates).toContain('@error="onEditorValidationError"')
    expect(templates).toContain('@error="onGenerationValidationError"')
    expect(templates).toContain('generationConfirmGuard.submit')
  })

  it('preserva filtro de responsável mesmo quando as opções ainda não carregaram', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    expect(workspace).toContain('createWorkAssigneeFilterModel')
    expect(workspace).toContain('Responsável #${f.assignee_membership_id}')
  })
})
