import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)
const read = (...parts: string[]) => readFileSync(root(...parts), 'utf8')

describe('shell-datatable-sort-contract', () => {
  it('guides: sortHeader só em colunas com map API; sem sort por id', () => {
    const source = read('app/pages/monitoring/guides.vue')
    expect(source).toContain('GUIDE_SORT_COLUMN_TO_API')
    expect(source).toContain('client: \'client_id\'')
    expect(source).toContain('competence: \'competence\'')
    expect(source).toContain('due: \'due_at\'')
    expect(source).toContain('resolveGuideSortApi')
    expect(source).toContain('sort: resolveGuideSortApi')
    expect(source).toContain('direction: sort?.desc')
    expect(source).toContain('sortHeader(\'Cliente\'')
    expect(source).toContain('sortHeader(\'Competência\'')
    expect(source).toContain('sortHeader(\'Vencimento\'')
    expect(source).not.toContain('sortHeader(\'ID\'')
    expect(source).toMatch(/id:\s*'id'[\s\S]*?enableSorting:\s*false/)
    expect(source).toContain('watch(sorting')
    expect(source).not.toContain('useRoute()')
    expect(source).not.toContain('useRouter()')
    expect(source).not.toContain('route.query')
    expect(source).not.toContain('router.replace')
  })

  it('registrations e tax-processes: sem sortHeader fantasma', () => {
    for (const rel of [
      'app/pages/monitoring/registrations.vue',
      'app/pages/monitoring/tax-processes.vue'
    ]) {
      const source = read(rel)
      expect(source, rel).not.toContain('sortHeader')
      expect(source, rel).not.toContain('from \'~/utils/table-sort\'')
      expect(source, rel).toMatch(/header:\s*'Cliente'/)
      expect(source, rel).toContain('enableSorting: false')
      // ModuleTable exige :sorting, mas colunas ficam com enableSorting: false (sem chrome fantasma).
      expect(source, rel).toContain(':sorting=')
    }
  })

  it('clientes: whitelist de sort sem cnpj', () => {
    const source = read('app/components/clients/CatalogList.vue')
    expect(source).not.toMatch(/sort\?\.id === 'cnpj'/)
    expect(source).toMatch(/sort\?\.id === 'is_active' \|\| sort\?\.id === 'tax_regime'/)
  })

  it('ByClient: mantém sort no estado da superfície + empty no slot', () => {
    const source = read('app/components/docs/ByClient.vue')
    expect(source).toContain('syncByClientNavigationState')
    expect(source).toContain('normalizeByClientSorting')
    expect(source).toContain('SURFACE_NAVIGATION.documents.byClient')
    expect(source).toContain('sort_direction')
    expect(source).toContain(':manual-sorting="true"')
    expect(source).toContain('#empty')
    expect(source).not.toContain('route.query')
    expect(source).not.toMatch(/ShellDataTable[\s\S]*v-if="loading \|\| rows\.length"/)
  })

  it('work processos e fila Lista: sortHeader só com whitelist API', () => {
    const processes = read('app/pages/work/processes/index.vue')
    expect(processes).toContain(':manual-sorting="true"')
    expect(processes).toContain('sortHeader(isClientMode.value ? \'Cliente\' : \'Processo\'')
    expect(processes).toContain('sortHeader(isClientMode.value ? \'Processos\' : \'Instâncias\'')
    expect(processes).toContain('sortHeader(\'Tarefas abertas\'')
    expect(processes).toContain('sortHeader(\'Próximo prazo\'')
    expect(processes).toContain('sortHeader(\'Progresso\'')
    expect(processes).toContain('WORK_PROCESS_GROUP_SORT_WHITELIST')
    expect(processes).not.toContain('WORK_PROCESS_FLAT_SORT_WHITELIST')
    expect(processes).toContain('buildProcessGroupsListParams')
    expect(processes).not.toContain('buildFlatProcessesListParams')
    expect(processes).toContain('enableSorting: false')

    const queue = read('app/components/work/WorkQueueWorkspace.vue')
    expect(queue).toContain(':manual-sorting="true"')
    expect(queue).toContain('sortHeader(\'Tarefa\'')
    expect(queue).toContain('effective_due_date')
    expect(queue).toContain('client_name')
    expect(queue).toContain('assignee_name')
    expect(queue).toContain('enableSorting: false')
  })

  it('listas N1: empty no #empty do ShellDataTable', () => {
    for (const rel of [
      'app/pages/syncs.vue',
      'app/pages/health.vue',
      'app/pages/admin/serpro/contracts.vue',
      'app/components/docs/Catalog.vue'
    ]) {
      const source = read(rel)
      expect(source, rel).toContain('#empty')
      expect(source, rel).toContain('ShellDataTable')
    }
  })
})
