import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { createWorkApi } from '../../app/composables/api/createWorkApi'
import type { ApiClient, ApiUrl } from '../../app/composables/api/types'
import type { ProcessAudienceRules } from '../../app/types/work'
import {
  buildGenerationSelection,
  cloneProcessAudienceRules,
  generationItemClientLabel,
  generationItemClientMeta
} from '../../app/utils/work-orchestration'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

function apiHarness() {
  const clientMock = vi.fn(async () => ({ data: [] }))
  const client = clientMock as unknown as ApiClient
  const apiUrl = vi.fn((path: string) => path) as ApiUrl
  return { api: createWorkApi(client, apiUrl), clientMock }
}

describe('orquestração dos modelos de trabalho', () => {
  it('normaliza regras e exceções antes do preview', () => {
    const rules = cloneProcessAudienceRules({
      tax_regimes: ['MEI', 'MEI', 'LUCRO_REAL'],
      category_ids: [8, 8, -1, 0],
      category_match: 'ALL',
      excluded_category_ids: [13, 13, Number.NaN]
    })

    expect(rules).toEqual({
      tax_regimes: ['MEI', 'LUCRO_REAL'],
      category_ids: [8],
      category_match: 'ALL',
      excluded_category_ids: [13]
    })

    expect(buildGenerationSelection(rules, [5, 5, 7], [7, 9, 0])).toEqual({
      rules,
      include_client_ids: [5, 7],
      exclude_client_ids: [7, 9]
    })
  })

  it('mapeia catálogo, instalação e preview estruturado sem tenant_id', async () => {
    const { api, clientMock } = apiHarness()
    const rules: ProcessAudienceRules = {
      tax_regimes: ['SIMPLES_NACIONAL'],
      category_ids: [4],
      category_match: 'ANY',
      excluded_category_ids: [9]
    }
    const selection = buildGenerationSelection(rules, [17], [18])

    await api.work.templates.catalog()
    await api.work.templates.installCatalog('PGDAS_MENSAL')
    await api.work.templates.preview(31, { competence: '2026-07', selection })

    expect(clientMock).toHaveBeenNthCalledWith(1, '/api/v1/work/template-catalog')
    expect(clientMock).toHaveBeenNthCalledWith(2, '/api/v1/work/template-catalog/PGDAS_MENSAL/install', {
      method: 'POST',
      body: {}
    })
    expect(clientMock).toHaveBeenNthCalledWith(3, '/api/v1/work/templates/31/preview', {
      method: 'POST',
      body: { competence: '2026-07', selection }
    })
    expect(JSON.stringify(clientMock.mock.calls)).not.toContain('tenant_id')
  })

  it('expõe identidade explicativa no item da prévia', () => {
    const item = {
      id: 1,
      client_id: 17,
      status: 'PREVIEWED',
      is_blocked: false,
      preview_payload: {
        selection: {
          client_name: 'Empresa Exemplo Ltda.',
          cnpj_masked: '12.345.678/0001-90',
          tax_regime: 'SIMPLES_NACIONAL'
        }
      }
    }

    expect(generationItemClientLabel(item)).toBe('Empresa Exemplo Ltda.')
    expect(generationItemClientMeta(item)).toBe('12.345.678/0001-90 · Simples Nacional')
  })
})

describe('listagem tabular de processos', () => {
  it('lista grupos por rotina/cliente com árvore multi-expand e links canônicos', () => {
    const page = source('app/pages/work/processes/index.vue')

    expect(page).toContain('ShellDataTable')
    expect(page).toContain('work-processes-table')
    expect(page).toContain('v-model:expanded')
    expect(page).toContain('#expanded')
    expect(page).toContain('work-process-group-expand')
    expect(page).toContain('work-process-child-expand')
    expect(page).toContain('expandedChildProcessIds')
    expect(page).toContain('toggleProcessIdExpanded')
    expect(page).toContain('work-process-group-tree')
    expect(page).toContain('work-process-group-children-pagination')
    expect(page).toContain('WorkTaskStatusSelect')
    expect(page).toContain('cascadeProcessTaskSelection')
    expect(page).toContain('workProcessSectionPath(process.id)')
    expect(page).toContain('openProcess')
    expect(page).toContain('WorkBulkActionsModal')
    expect(page).toContain('can-update-processes')
    expect(page).toContain('manual-sorting')
    expect(page).toContain('sortHeader')
    expect(page).toContain('enableSorting: false')
    expect(page).toContain('ShellListFilterToolbar')
    expect(page).toContain('work-processes-bulk-actions')
    expect(page).toContain('openBulkActions')
    expect(page).toContain('#actions')
    expect(page).toContain('work-processes-toolbar')
    expect(page).toContain('WorkEntityLevelToggle')
    expect(page).toContain('api.work.processGroups.list')
    expect(page).toContain('buildProcessGroupsListParams')
    expect(page).toContain('buildGroupChildrenListParams')
    expect(page).toContain('groupBy.value')
    expect(page).not.toContain('@select="onProcessTableSelect"')
    expect(page).not.toContain('WorkProcessAccordionList')
    expect(page).not.toContain('buildFlatProcessesListParams')
    expect(page).not.toContain('overflow-x-auto')
  })

  it('expõe bulk de processos e tarefas no cliente API', async () => {
    const { api, clientMock } = apiHarness()
    await api.work.processes.bulk({
      items: [{ id: 1, lock_version: 1 }],
      changes: { action: 'assign', assignee_membership_id: 9 }
    })
    await api.work.tasks.bulk({
      items: [{ id: 2, lock_version: 3 }],
      changes: { action: 'start' }
    })
    expect(clientMock).toHaveBeenCalledWith('/api/v1/work/processes/bulk', {
      method: 'POST',
      body: {
        items: [{ id: 1, lock_version: 1 }],
        changes: { action: 'assign', assignee_membership_id: 9 }
      }
    })
    expect(clientMock).toHaveBeenCalledWith('/api/v1/work/tasks/bulk', {
      method: 'POST',
      body: {
        items: [{ id: 2, lock_version: 3 }],
        changes: { action: 'start' }
      }
    })
  })
})

describe('integração entre modelos, tarefas e monitoramento', () => {
  it('oferece biblioteca, edição e seleção avançada sem campo bruto de IDs', () => {
    const templates = source('app/pages/work/templates/index.vue')

    for (const token of [
      'Biblioteca',
      'Minhas rotinas',
      'installCatalog',
      'audienceRules',
      'FiscalClientPicker',
      'Pré-visualizar empresas'
    ]) {
      expect(templates).toContain(token)
    }
    expect(templates).not.toContain('IDs de clientes')
  })

  it('carrega trabalho do cliente com falha independente e link filtrado', () => {
    const page = source('app/pages/monitoring/clients/[clientId].vue')
    const block = source('app/components/monitoring/ClientWork.vue')

    expect(page).toContain('operationalWorkState')
    expect(page).toContain('void loadOperationalWork(force)')
    expect(page).toContain('active_only: true')
    expect(page).toContain('MonitoringClientWork')
    expect(block).toContain('Trabalho operacional')
    expect(block).toContain('publishSurfaceNavigationIntent(\'work-process-grouping\', { client_id: props.clientId })')
    expect(block).toContain('navigateTo(\'/work/processes\')')
    expect(block).not.toContain('/work/processes?')
    expect(block).toContain('progress_percent')
  })

  it('consolida a visão transversal sob o nome Tarefas', () => {
    const navigation = source('app/utils/work-navigation.ts')
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const chrome = source('app/components/work/WorkQueueChrome.vue')

    expect(navigation).toContain('label: \'Tarefas\'')
    expect(workspace).toContain('<WorkQueueChrome')
    expect(chrome).toContain('title="Tarefas"')
    expect(navigation).not.toContain('Minha fila')
  })

  it('oferece toggle Fila|Lista|Kanban sincronizado com view na query', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const chrome = source('app/components/work/WorkQueueChrome.vue')
    const filters = source('app/composables/useWorkQueueFilters.ts')

    expect(chrome).toContain('work-queue-view-toggle')
    expect(chrome).toContain('WorkEntityLevelToggle')
    expect(chrome).toContain('value: \'lista\'')
    expect(chrome).toContain('value: \'kanban\'')
    expect(workspace).toContain('@update:view="setQueueView"')
    expect(workspace).toContain('isKanban')
    expect(workspace).toContain('WorkKanbanBoard')
    expect(workspace).toContain('work-queue-kanban-panel')
    expect(workspace).toContain('ShellDataTable')
    expect(workspace).toContain('work-queue-table')
    expect(workspace).toContain('WorkBulkActionsModal')
    expect(workspace).toContain('WorkTaskStatusSelect')
    expect(workspace).toContain('manual-sorting')
    expect(workspace).toContain('sortHeader')
    expect(workspace).toContain('work-queue-bulk-actions')
    expect(workspace).toContain('openBulkActions')
    expect(workspace).toContain('#actions')
    expect(filters).toContain('view: WorkQueueView')
    expect(filters).toContain('\'fila\' | \'lista\' | \'kanban\'')
    expect(filters).toContain('view: f.view === \'fila\' ? undefined : f.view')
    expect(filters).toContain('sort: f.sort || undefined')
  })

  it('Kanban usa tabs condicionais todas|hoje|atrasadas|semana com coerção ao trocar visão', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const filters = source('app/composables/useWorkQueueFilters.ts')

    expect(workspace).toContain('kanbanTabs')
    expect(workspace).toContain('filaListaTabs')
    expect(workspace).toContain('isKanban.value ? kanbanTabs : filaListaTabs')
    expect(workspace).toContain('label: \'Todas\'')
    expect(workspace).toContain('value: \'todas\'')
    expect(workspace).toContain('coerceWorkQueueTabForView(filters.value.tab, next)')
    expect(workspace).toContain('patch({ view: next, tab }')
    expect(filters).toContain('coerceWorkQueueTabForView')
    expect(filters).toContain('defaultWorkQueueTab')
    expect(filters).toContain('view === \'kanban\' ? \'todas\' : \'open\'')
    expect(filters).toContain('shouldOmitTabInQuery')
  })

  it('Kanban exclui auto-seleção e usa slideover de detalhe', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const board = source('app/components/work/WorkKanbanBoard.vue')
    expect(workspace).toContain('isKanban.value')
    expect(workspace).toContain('params.per_page = 100')
    expect(workspace).toContain('isLista.value || isKanban.value || isMobile.value')
    expect(workspace).toContain('&& isFila.value')
    expect(board).toContain('work-kanban-truncation-banner')
    expect(board).toContain('work-kanban-block-modal')
    expect(board).toContain('work-kanban-reopen-modal')
    expect(board).toContain('completeKanbanDnDDrop')
    expect(board).not.toMatch(/return false/)
    expect(board).not.toMatch(/finishPending\(false\)/)
    expect(board).toContain('DnDProvider')
    expect(board).toContain('api.work.tasks.reopen')
  })

  it('cliente API expõe reopen de tarefa', async () => {
    const { api, clientMock } = apiHarness()
    await api.work.tasks.reopen(9, 2, 'reabrir por erro')
    expect(clientMock).toHaveBeenCalledWith('/api/v1/work/tasks/9/reopen', {
      method: 'POST',
      body: { lock_version: 2, justification: 'reabrir por erro' }
    })
  })

  it('Fila desktop: detalhe colapsável sem auto-seleção da primeira tarefa', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const chrome = source('app/components/work/WorkQueueChrome.vue')
    expect(workspace).toContain('detailOpen')
    expect(workspace).toContain('toggleDetail')
    expect(chrome).toContain('work-queue-detail-toggle')
    expect(workspace).toContain('detailPaneVisible')
    expect(workspace).not.toContain('suppressAutoSelect')
    expect(workspace).not.toContain('await select(items.value[0]')
    expect(workspace).toMatch(/apiErrorStatus\(e\) === 404[\s\S]*await clearSelection\(\)/)
    expect(workspace).toContain('loadDetail(id)')
    expect(workspace).not.toContain('work-queue-neutral')
  })

  it('Fila eleva chrome compartilhado acima do split (padrão mailbox)', () => {
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    const chrome = source('app/components/work/WorkQueueChrome.vue')
    const detail = source('app/components/work/WorkTaskDetailPanel.vue')

    // Chrome full-width único acima das três visões.
    expect(workspace).toContain('flex min-h-0 min-w-0 w-full flex-1 flex-col')
    expect(workspace.match(/<WorkQueueChrome/g)).toHaveLength(1)
    expect(chrome.match(/test-id="page-navbar"/g)).toHaveLength(1)
    expect(chrome.match(/data-testid="work-queue-toolbar"/g)).toHaveLength(1)
    expect(workspace).toContain('data-testid="work-queue-panel"')

    // Navbar: badge; toolbar: entity + visão + detalhe condicional.
    expect(chrome).toMatch(
      /#trailing[\s\S]*?work-queue-total[\s\S]*?UDashboardToolbar[\s\S]*?WorkEntityLevelToggle[\s\S]*?work-queue-view-toggle[\s\S]*?work-queue-detail-toggle/
    )

    // Painel lista sem navbar/toolbar de página no header interno
    const filaPanel = workspace.match(/id="work-queue-list"[\s\S]*?<\/UDashboardPanel>/)?.[0] ?? ''
    expect(filaPanel).toContain('<template #body>')
    expect(filaPanel).not.toContain('<template #header>')
    expect(workspace).not.toContain('<UDashboardNavbar')
    expect(workspace).not.toContain('<UDashboardToolbar')

    // Título do detalhe truncável sem invadir a lista
    expect(detail).toContain('min-w-0 overflow-hidden')
    expect(detail).toContain('title: \'truncate min-w-0\'')
    expect(detail).toContain('work-task-detail-navbar')
  })
})
