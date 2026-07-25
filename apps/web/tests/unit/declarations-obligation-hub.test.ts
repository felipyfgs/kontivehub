import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  DECLARATIONS_TABS,
  declarationsSurfaceTitle,
  normalizeDeclarationsSubmodule
} from '../../app/types/fiscal-modules'

describe('declarations-obligation-hub', () => {
  it('normaliza submodule com default PGDAS e aliases', () => {
    expect(normalizeDeclarationsSubmodule(undefined)).toBe('PGDAS')
    expect(normalizeDeclarationsSubmodule('')).toBe('PGDAS')
    expect(normalizeDeclarationsSubmodule('pgdasd')).toBe('PGDAS')
    expect(normalizeDeclarationsSubmodule('PGDAS_D')).toBe('PGDAS')
    expect(normalizeDeclarationsSubmodule('dctf')).toBe('DCTFWEB')
    expect(normalizeDeclarationsSubmodule('DEFIS')).toBe('DEFIS')
    expect(normalizeDeclarationsSubmodule('dasnsimei')).toBe('DASN_SIMEI')
    expect(normalizeDeclarationsSubmodule('mit')).toBe('MIT')
    expect(normalizeDeclarationsSubmodule('dirf')).toBe('DIRF')
  })

  it('expõe as cinco obrigações oficiais e duas coberturas externas', () => {
    expect(DECLARATIONS_TABS.map(t => t.value)).toEqual([
      'PGDAS',
      'DEFIS',
      'DASN_SIMEI',
      'DCTFWEB',
      'MIT',
      'FGTS',
      'DIRF'
    ])
  })

  it('monta título dinâmico da superfície', () => {
    expect(declarationsSurfaceTitle('PGDAS')).toBe('PGDAS-D - Declarações')
    expect(declarationsSurfaceTitle('DASN_SIMEI')).toBe('DASN-SIMEI - Declarações')
    expect(declarationsSurfaceTitle('DCTFWEB')).toBe('DCTFWeb - Declarações')
    expect(declarationsSurfaceTitle('DIRF')).toBe('DIRF - Declarações')
  })

  it('página declarações usa tabs locais, default PGDAS e colunas/histórico PGDAS', () => {
    const page = readFileSync(
      resolve(process.cwd(), 'app/pages/monitoring/declarations.vue'),
      'utf8'
    )
    const kpiSource = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/KpiStrip.vue'),
      'utf8'
    )
    const tabsStart = page.indexOf('<ShellScrollableTabs')
    const tabsMarkup = page.slice(tabsStart, page.indexOf('/>', tabsStart) + 2)
    const kpiTabsStart = kpiSource.indexOf('<ShellScrollableTabs')
    const kpiTabsMarkup = kpiSource.slice(
      kpiTabsStart,
      kpiSource.indexOf('/>', kpiTabsStart) + 2
    )

    expect(page).toContain('declarations-submodule-tabs')
    expect(page).toContain('normalizeDeclarationsSubmodule(\'PGDAS\')')
    expect(page).toContain('useFiscalModulePortfolio(\'declarations\'')
    expect(page).toContain('ShellScrollableTabs')
    expect(page).toContain('MonitoringPgdasdDasHistoryModal')
    expect(page).toContain('MonitoringDctfwebHistoryModal')
    expect(page).toContain('MonitoringDefisDeclarationsModal')
    expect(page).toContain('MonitoringMeiPublicServicesModal')
    expect(page).toContain('initial-service="dasn"')
    expect(page).toContain('MonitoringMitListaApuracoesModal')
    expect(page).toContain('MonitoringDeclarationOperationModal')
    expect(page).toContain('activeOperations')
    expect(page).toContain('onOperations: openOperations')
    expect(page).toContain('loadDeclarationCatalog')
    expect(page).toContain('aria-label="Filtrar por declaração"')
    expect(page).toContain('badge: tabBadge(t.value)')
    expect(page).toContain('overview.value?.metrics?.tab_counts?.[key]')
    expect(page).toContain('? \'…\' : \'—\'')
    for (const markup of [tabsMarkup, kpiTabsMarkup]) {
      expect(markup).toContain('size="md"')
      expect(markup).toContain('class="w-full min-w-0 max-w-full"')
      expect(markup).not.toContain('color=')
      expect(markup).not.toContain('variant=')
      expect(markup).not.toContain(':ui=')
    }
    expect(page).toContain('class="w-full min-w-0 flex-1"')
    expect(page.indexOf('class="w-full min-w-0 flex-1"')).toBeLessThan(
      page.indexOf('data-testid="declarations-operations-open"')
    )
    expect(page).not.toContain('MonitoringDeclarationsCoverageSummary')
    expect(page).not.toContain('MonitoringDeclarationsOperationsPanel')
    expect(page).toContain('declarations-dirf-unsupported')
    expect(page).not.toMatch(/navigateTo\([^)]*declarations\//)
  })

  it('central declarativa usa action_id e não envia coordenadas técnicas pelo cliente', () => {
    const api = readFileSync(
      resolve(process.cwd(), 'app/composables/api/createFiscalApi.ts'),
      'utf8'
    )
    const modal = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/DeclarationOperationModal.vue'),
      'utf8'
    )
    expect(api).toContain('/fiscal/declarations/operations/${encodeURIComponent(actionId)}/read')
    expect(api).toContain('/fiscal/declarations/operations/${encodeURIComponent(actionId)}/preflight')
    expect(api).toContain('/fiscal/declarations/operations/${encodeURIComponent(actionId)}/execute')
    expect(modal).toContain('Nenhuma ação ocorre ao abrir este modal.')
    expect(modal).toContain('declaration-operation-confirmation-phrase')
    expect(modal).not.toContain('id_sistema')
    expect(modal).not.toContain('id_servico')
  })

  it('modal MEI aceita iniciar diretamente no histórico DASN-SIMEI', () => {
    const modal = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/MeiPublicServicesModal.vue'),
      'utf8'
    )
    expect(modal).toContain('initialService?: \'ccmei\' | \'dasn\'')
    expect(modal).toContain('initialService: \'ccmei\'')
    expect(modal).toContain('activeService.value === \'dasn\'')
  })

  it('colunas PGDAS cobrem Situação / Últ. Declaração / Cliente / Ações · Consulta, sem Histórico na grade', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/utils/declarations-table.ts'),
      'utf8'
    )
    const [pgdasSection] = source.split('/** Colunas genéricas filtradas por obrigação')
    expect(source).toContain('Situação da declaração')
    expect(source).toContain('Últ. Declaração')
    expect(pgdasSection).toContain('MONITORING_ACTIONS_LABEL')
    expect(pgdasSection).toContain('MONITORING_CONSULTED_LABEL')
    expect(pgdasSection).not.toMatch(/id:\s*'history'/)
    expect(pgdasSection).not.toContain('Última Busca')
    expect(pgdasSection).toContain('Histórico de busca')
    expect(pgdasSection).toContain('declarations-pgdas-row-actions')
  })

  it('modal DAS mantém histórico próprio sem abrir modal aninhado de declarações', () => {
    const das = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/PgdasdDasHistoryModal.vue'),
      'utf8'
    )
    expect(das).toContain('DAS Simples Nacional - Histórico')
    expect(das).toContain('MAEDs não são enviadas automaticamente')
    expect(das).toContain('Ano da busca')
    expect(das).toContain('Baixar DAS')
    expect(das).toContain('fetchHistory')
    expect(das).not.toContain('MonitoringPgdasdDeclarationsHistoryModal')
    expect(das).not.toContain('pgdasd-das-open-declarations')
  })
})
