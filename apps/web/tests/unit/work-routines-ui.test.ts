import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { WORK_ROUTINES_COPY_SURFACES, WORK_ROUTINES_GLOSSARY } from '../../app/utils/work-routines-glossary'
import { WORK_NAV_ITEMS } from '../../app/utils/work-navigation'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

describe('work-routines-ui', () => {
  it('define glossário Rotina/Coordenador/Executor sem Modelo como rótulo principal', () => {
    expect(WORK_ROUTINES_GLOSSARY.rotina.plural).toBe('Rotinas')
    expect(WORK_ROUTINES_GLOSSARY.coordenador.singular).toBe('Coordenador')
    expect(WORK_ROUTINES_GLOSSARY.executor.singular).toBe('Executor')
  })

  it('usa Rotinas na navegação Work mantendo path técnico /work/templates', () => {
    const templatesNav = WORK_NAV_ITEMS.find(item => item.id === 'work-templates')
    expect(templatesNav?.label).toBe('Rotinas')
    expect(templatesNav?.to).toBe('/work/templates')
  })

  it('expõe agenda de recorrência e copy Rotina nas superfícies Work', () => {
    const templates = source('app/pages/work/templates/index.vue')
    expect(templates).toContain('data-testid="work-template-recurrence"')
    expect(templates).toContain('updateRecurrence')
    expect(templates).toContain('Minhas rotinas')
    expect(templates).toContain('header: \'Rotina\'')
    expect(templates).toContain('header: \'Agenda\'')

    const api = source('app/composables/api/createWorkApi.ts')
    expect(api).toContain('getRecurrence')
    expect(api).toContain('updateRecurrence')
    expect(api).toContain('generationBatches')
    expect(api).toContain('retry')

    const processes = source('app/pages/work/processes/index.vue')
    expect(processes).not.toContain('Rotinas / lote')
    expect(processes).toContain('agrupados por rotina')
    expect(processes).toContain('group_by=routine')
    expect(processes).toContain('WorkEntityLevelToggle')
    expect(processes).toContain('Sem responsável')
    expect(processes).toContain('work-process-child-expand')
    expect(processes).toContain('requires-evidence')
    expect(processes).toContain('data-testid="work-process-task-detail"')
    expect(processes).toContain('WorkTaskStatusSelect')

    const detail = source('app/pages/work/processes/[id].vue')
    expect(detail).toContain('Coordenador')
    expect(detail).toContain('Executor:')

    const queue = source('app/components/work/WorkQueueWorkspace.vue')
    expect(queue).toContain('a partir de uma rotina')
    expect(queue).toContain(':requires-evidence')

    const assistant = source('app/composables/useAssistantChat.ts')
    expect(assistant).toContain('Abrir rotinas')
    expect(assistant).toContain('Work → Rotinas')

    for (const surface of WORK_ROUTINES_COPY_SURFACES) {
      expect(source(surface).length).toBeGreaterThan(0)
    }
  })

  it('compõe Biblioteca e Minhas rotinas como duas listas de gestão responsivas', () => {
    const templates = source('app/pages/work/templates/index.vue')

    expect(templates.match(/<ShellDataTable/g)).toHaveLength(2)
    expect(templates.match(/<ShellListFilterToolbar/g)).toHaveLength(1)
    expect(templates).toContain('test-id="work-template-catalog-table"')
    expect(templates).toContain('mobile-cards-test-id="work-template-catalog-mobile-cards"')
    expect(templates).toContain(':get-row-id="item => item.key"')
    expect(templates).toContain('test-id="work-templates-table"')
    expect(templates).toContain('mobile-cards-test-id="work-templates-mobile-cards"')
    expect(templates).toContain(':get-row-id="template => String(template.id)"')
    expect(templates).toContain('primary-column-id="name"')
    expect(templates).toContain('status-column-id="installed"')
    expect(templates).toContain('status-column-id="is_active"')
    expect(templates).toContain('Buscar na biblioteca de rotinas')
    expect(templates).toContain('Buscar nas rotinas do escritório')
  })

  it('não simula paginação no catálogo local e preserva paginação/sorting API no escritório', () => {
    const templates = source('app/pages/work/templates/index.vue')
    const catalogTable = templates.split('test-id="work-template-catalog-table"')[1]
      ?.split('</ShellDataTable>')[0] ?? ''
    const tenantTable = templates.split('test-id="work-templates-table"')[1]
      ?.split('</ShellDataTable>')[0] ?? ''

    expect(templates).toContain('O catálogo é uma coleção local curta e não paginada pela API.')
    expect(catalogTable).toContain(':show-per-page="false"')
    expect(catalogTable).toContain(':show-pagination="false"')
    expect(catalogTable).toContain(':total="filteredCatalog.length"')
    expect(tenantTable).toContain(':manual-sorting="true"')
    expect(tenantTable).toContain(':sorting="tenantSortingState"')
    expect(tenantTable).toContain('@update:sorting="onTenantSortingUpdate"')
    expect(tenantTable).toContain('@update:page="page = $event"')
    expect(tenantTable).toContain('@update:items-per-page="setPerPage"')
    expect(templates).toContain('TENANT_TEMPLATE_SORTS')
    expect(templates).toContain('sort: tenantSort.value || undefined')
    expect(templates).toContain('direction: tenantSort.value ? tenantSortDirection.value : undefined')
  })

  it('remove catálogo e preview decorativos sem perder estados, ações e permissões', () => {
    const templates = source('app/pages/work/templates/index.vue')

    expect(templates).not.toContain('grid gap-4 md:grid-cols-2 xl:grid-cols-3')
    expect(templates).not.toContain('<UPageCard')
    expect(templates).not.toContain('<UCard')
    expect(templates).toContain('<dl')
    expect(templates).toContain('data-testid="work-generation-preview-summary"')
    expect(templates).toContain('catalogEmptyKind')
    expect(templates).toContain('tenantEmptyKind')
    expect(templates).toContain('v-if="catalogError && catalog.length"')
    expect(templates).toContain('v-if="catalogError && !catalog.length"')
    expect(templates).toContain('work-template-catalog-stale-error')
    expect(templates).toContain('work-template-catalog-error')
    expect(templates).toContain('work-templates-stale-error')
    expect(templates).toContain('work-templates-error')
    expect(templates).toContain('@retry="loadCatalog"')
    expect(templates).toContain('@retry="loadTemplates"')
    expect(templates).toContain('Adicionar ao escritório')
    expect(templates).toContain('Abrir minhas rotinas')
    expect(templates).toContain('v-if="canManageCatalog"')
    expect(templates).toContain('v-if="canGenerateProcesses"')
    expect(templates).toContain('editor.lockVersion = lockVersion')
    expect(templates).toContain('generationConfirmGuard.submit')
  })

  it('mantém vazio filtrado e limpar busca quando a última biblioteca válida fica stale', () => {
    const templates = source('app/pages/work/templates/index.vue')
    const catalogSurface = templates.split('v-if="view === \'library\'"')[1]
      ?.split('<section v-else')[0] ?? ''
    const catalogEmpty = catalogSurface.split('<template #empty>')[1]
      ?.split('</template>')[0] ?? ''

    expect(catalogSurface).toContain('v-if="catalogError && catalog.length"')
    expect(catalogSurface).toContain('work-template-catalog-stale-error')
    expect(catalogEmpty).toContain('v-if="catalogError && !catalog.length"')
    expect(catalogEmpty).toContain('work-template-catalog-error')
    expect(catalogEmpty).toContain('<UEmpty')
    expect(catalogEmpty).toContain('v-else')
    expect(catalogEmpty).toContain('v-if="query" #actions')
    expect(catalogEmpty).toContain('label="Limpar busca"')
    expect(catalogEmpty).toContain('@click="clearQuery"')
  })
})
