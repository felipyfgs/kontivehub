import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { MeUser } from '~/types/api'
import { clientDetailNav } from '~/utils/client-detail-navigation'
import { clientFiscalDetailNav } from '~/utils/client-fiscal-detail-navigation'
import { fiscalNavLeaves } from '~/utils/fiscal-navigation'
import { LINK_TABS_UI, SCROLLABLE_TABS_UI, TOUCH_SCROLL_X } from '~/utils/list-filter-layout'
import { MONITORING_NAV_ITEMS } from '~/utils/monitoring-nav'
import {
  mainDestinations,
  monitoringDestinations,
  searchableDestinations,
  toNavigationItems
} from '~/utils/navigation'
import { resolveNavSelection } from '~/utils/navigation-hierarchy'
import { workProcessContextNav } from '~/utils/work-navigation'

const viewer = {
  id: 10,
  role: 'VIEWER',
  context_status: 'ready'
} as MeUser

describe('navegação global no sidebar', () => {
  it('organiza os 12 contextos sob um único acordeão Monitoramento', () => {
    const destinations = monitoringDestinations(viewer, '/monitoring/declarations')
    const monitoring = destinations[0]

    expect(destinations).toHaveLength(1)
    expect(monitoring).toMatchObject({
      id: 'monitoring',
      label: 'Monitoramento',
      type: 'trigger',
      defaultOpen: true
    })
    expect(monitoring).not.toHaveProperty('active')
    expect(monitoring?.children?.map(item => item.label)).toEqual([
      'Dashboard',
      'Simples Nacional',
      'MEI',
      'DCTFWeb',
      'FGTS Digital',
      'Parcelamentos',
      'Situação Fiscal',
      'Caixas Postais',
      'Declarações',
      'Guias',
      'Cadastro e Vínculos',
      'Processos Fiscais'
    ])
    expect(monitoring?.children?.find(item => item.id === 'monitoring-declarations'))
      .toMatchObject({ active: true, to: '/monitoring/declarations' })
    expect(monitoring?.children?.every(item => !item.children?.length)).toBe(true)
  })

  it('converte Monitoramento e suas folhas para o contrato nativo do UNavigationMenu', () => {
    const items = toNavigationItems(
      monitoringDestinations(viewer, '/monitoring/simples')
    )

    expect(items[0]).toMatchObject({
      label: 'Monitoramento',
      type: 'trigger',
      defaultOpen: true
    })
    expect(items[0]).not.toHaveProperty('active')
    expect(items[0]?.children?.filter(item => item.active)).toHaveLength(1)
    expect(items[0]?.children)
      .toEqual(expect.arrayContaining([
        expect.objectContaining({ label: 'Simples Nacional', to: '/monitoring/simples' }),
        expect.objectContaining({ label: 'MEI', to: '/monitoring/mei' }),
        expect.objectContaining({ label: 'Declarações', to: '/monitoring/declarations' })
      ]))
  })

  it('preserva permissões e indexa todas as folhas fiscais na busca global', () => {
    expect(monitoringDestinations({ id: 11 } as MeUser, '/monitoring')).toEqual([])

    const searchable = searchableDestinations(viewer, { path: '/monitoring' })
    const searchablePaths = new Set(searchable.map(item => item.to))
    for (const leaf of fiscalNavLeaves()) {
      expect(searchablePaths.has(leaf.to)).toBe(true)
    }
  })

  it('mantém o catálogo e a ordem da navegação como fonte única', () => {
    expect(fiscalNavLeaves().map(item => item.label))
      .toEqual(MONITORING_NAV_ITEMS.map(item => item.label))
  })

  it('alinha os nomes do sidebar às 12 superfícies operacionais', () => {
    const pageContracts: Array<[string, string | RegExp]> = [
      ['app/pages/monitoring/index.vue', 'title="Dashboard"'],
      ['app/pages/monitoring/simples/index.vue', 'submodule="PGDASD"'],
      ['app/pages/monitoring/mei/index.vue', 'submodule="PGMEI"'],
      ['app/pages/monitoring/dctfweb/index.vue', 'title="DCTFWeb"'],
      ['app/pages/monitoring/fgts.vue', 'title="FGTS Digital"'],
      ['app/pages/monitoring/installments.vue', 'title="Parcelamentos"'],
      ['app/pages/monitoring/sitfis.vue', 'title="Situação Fiscal"'],
      ['app/pages/monitoring/mailbox.vue', 'title="Caixas Postais"'],
      // Hub Declarações: título dinâmico por aba (`PGDAS - Declarações`).
      ['app/pages/monitoring/declarations.vue', /:title="surfaceTitle"|declarationsSurfaceTitle/],
      ['app/pages/monitoring/guides.vue', 'title="Guias"'],
      ['app/pages/monitoring/registrations.vue', 'title="Cadastro e Vínculos"'],
      ['app/pages/monitoring/tax-processes.vue', 'title="Processos Fiscais"']
    ]

    const navLabels = MONITORING_NAV_ITEMS.map(item => item.label)
    expect(navLabels).toContain('Declarações')
    expect(pageContracts.map(([path]) => path)).toHaveLength(12)
    for (const [path, title] of pageContracts) {
      const source = readFileSync(resolve(process.cwd(), path), 'utf8')
      if (typeof title === 'string') {
        expect(source).toContain(title)
      } else {
        expect(source).toMatch(title)
      }
    }
  })

  it('expõe Escritórios, Módulos fiscais e um único SERPRO dentro de Admin', () => {
    const user = {
      id: 1,
      is_platform_admin: true,
      context_status: 'office_context_required'
    } as MeUser
    const admin = mainDestinations(user, { path: '/admin/serpro/contracts' })[0]

    expect(admin).toMatchObject({ id: 'platform-admin', type: 'trigger', defaultOpen: true })
    expect(admin?.children?.map(item => item.label)).toEqual([
      'Escritórios',
      'Módulos fiscais',
      'SERPRO'
    ])
    expect(admin?.children?.some(item => item.label?.startsWith('SERPRO ·'))).toBe(false)
    expect(admin?.children?.find(item => item.label === 'SERPRO'))
      .toMatchObject({ active: true, to: '/admin/serpro' })
  })
})

describe('navegação contextual Tabs → Subtabs', () => {
  it('detalhe do cliente: abas CRM path-based + detalhe fiscal plano', () => {
    const nav = clientDetailNav(7)
    expect(nav.map(item => item.id)).toEqual([
      'client-cadastro',
      'client-dados-adicionais',
      'client-contato',
      'client-departamento',
      'client-observacoes',
      'client-contratos'
    ])
    expect(nav.every(item => !('children' in item))).toBe(true)
    const cadastro = nav.find(item => item.id === 'client-cadastro')
    expect(cadastro && 'to' in cadastro ? cadastro.to : null).toBe('/clients/7/cadastro')

    const fiscalNav = clientFiscalDetailNav(8, { isMei: true })
    expect(fiscalNav.every(item => !('children' in item))).toBe(true)
    expect(fiscalNav.map(item => item.id)).toContain('cf-pgdasd')
    expect(fiscalNav.map(item => item.id)).toContain('cf-ccmei')
    expect(fiscalNav.map(item => item.id)).toContain('cf-tax-processes')
    expect(fiscalNav.map(item => item.id)).toContain('cf-dctfweb')
    expect(fiscalNav.map(item => item.id)).toContain('cf-mailbox')
    expect(fiscalNav.map(item => item.id)).not.toContain('cf-runs')
    expect(fiscalNav.map(item => item.id)).not.toContain('cf-pending')
    const fiscalClient = resolveNavSelection(
      fiscalNav,
      '/monitoring/clients/8/pgdasd'
    )
    expect(fiscalClient.group).toBeNull()
    expect(fiscalClient.leaf?.id).toBe('cf-pgdasd')
    expect(fiscalClient.leaf?.label).toBe('Simples Nacional')

    const snNav = clientFiscalDetailNav(8, { isMei: false })
    expect(snNav.map(item => item.id)).not.toContain('cf-ccmei')
  })

  it('preserva seções identificadas por query no detalhe de processo', () => {
    const process = resolveNavSelection(
      workProcessContextNav(42),
      '/work/processes/42?section=comentarios'
    )
    expect(process.group).toBeNull()
    expect(process.leaf?.id).toBe('process-comentarios')
  })

  it('renderiza tabs, subtabs opcionais e um seletor mobile — sem dropdown', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/components/navigation/SectionNavigation.vue'),
      'utf8'
    )

    expect(source.match(/<UNavigationMenu/g)).toHaveLength(2)
    expect(source.match(/<USelectMenu/g)).toHaveLength(1)
    expect(source).toContain('section-nav-subtabs')
    expect(source).toContain('toDesktopTabItems')
    expect(source).toContain('toDesktopSubtabItems')
    expect(source).not.toContain('children: group.children.map')
    expect(source).not.toContain('content-orientation="vertical"')
  })
})

describe('tabs locais', () => {
  it('mantém as tabs retraídas e delega a aparência ao tema padrão', () => {
    expect(LINK_TABS_UI.root).not.toContain('min-w-full')
    expect(LINK_TABS_UI.trigger).toContain('grow-0')
    expect(SCROLLABLE_TABS_UI.root).not.toContain('min-w-full')
    expect(SCROLLABLE_TABS_UI.trigger).toContain('grow-0')
    expect(SCROLLABLE_TABS_UI).not.toHaveProperty('indicator')
    expect(SCROLLABLE_TABS_UI.list).not.toMatch(/bg-|border|rounded|shadow/)
    expect(SCROLLABLE_TABS_UI.trigger).not.toMatch(/text-|bg-|rounded|shadow/)
  })

  it('limita scroll touch à largura do pai (overflow contido)', () => {
    expect(TOUCH_SCROLL_X).toContain('w-full')
    expect(TOUCH_SCROLL_X).toContain('max-w-full')
    expect(TOUCH_SCROLL_X).toContain('min-w-0')
    expect(TOUCH_SCROLL_X).toContain('overflow-x-auto')

    const kpiStrip = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/KpiStrip.vue'),
      'utf8'
    )
    const moduleTable = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/ModuleTable.vue'),
      'utf8'
    )
    expect(kpiStrip).toMatch(/fiscal-kpi-strip[\s\S]*?w-full min-w-0 max-w-full/)
    expect(moduleTable).toMatch(/fiscal-kpi-block[\s\S]*?w-full min-w-0 max-w-full|w-full min-w-0 max-w-full[\s\S]*?fiscal-kpi-block/)
  })

  it('aplica cápsulas compactas às alternâncias locais de monitoramento', () => {
    const sources = [
      'app/pages/monitoring/dctfweb/index.vue'
    ].map(path => readFileSync(resolve(process.cwd(), path), 'utf8'))

    for (const source of sources) {
      expect(source).toContain('variant="pill"')
    }

    const wrapper = readFileSync(
      resolve(process.cwd(), 'app/components/shell/ScrollableTabs.vue'),
      'utf8'
    )
    expect(wrapper).toContain('props.variant === \'link\' ? LINK_TABS_UI : SCROLLABLE_TABS_UI')
    expect(wrapper).toContain('activation-mode="automatic"')
  })

  it('console SERPRO usa só Visão geral e Configuração no shell, sem tabs locais nas hubs', () => {
    const nav = readFileSync(resolve(process.cwd(), 'app/utils/serpro-navigation.ts'), 'utf8')
    expect(nav).toContain('label: \'Visão geral\'')
    expect(nav).toContain('label: \'Configuração\'')
    expect(nav).not.toContain('label: \'Canário DTE\'')
    expect(nav).not.toContain('label: \'Operação\'')
    expect(nav).not.toContain('label: \'Integração\'')

    const overview = readFileSync(resolve(process.cwd(), 'app/pages/admin/serpro/index.vue'), 'utf8')
    const configuration = readFileSync(
      resolve(process.cwd(), 'app/pages/admin/serpro/configuration.vue'),
      'utf8'
    )
    expect(overview).not.toContain('ShellScrollableTabs')
    expect(configuration).not.toContain('ShellScrollableTabs')
    expect(overview).toContain('admin-serpro-overview-secondary-links')
    expect(configuration).toContain('admin-serpro-config-secondary-links')
    expect(configuration).not.toContain('serpro-config-pending-offices')
    expect(configuration).not.toContain('serpro-config-history')
    expect(configuration).toContain('serpro-config-credentials')
    expect(configuration).not.toContain('serpro-production-onboarding')
    expect(configuration).not.toContain('serpro-prod-step-')
    expect(configuration).toContain('serpro-prod-consent')
    expect(configuration).not.toContain('serpro-config-gates')
    expect(configuration).not.toContain('Liberações externas')
    expect(configuration).toContain('serpro-config-pfx')
    expect(configuration).not.toContain('environment !== \'PRODUCTION\' || productionOnboarding?.enabled')
  })

  it('mantém filtros e indicadores no preset pill padrão', () => {
    const filterSources = [
      'app/components/monitoring/KpiStrip.vue',
      'app/components/work/WorkQueueWorkspace.vue',
      'app/pages/work/calendar.vue',
      'app/pages/monitoring/installments.vue'
    ].map(path => readFileSync(resolve(process.cwd(), path), 'utf8'))

    for (const source of filterSources) {
      expect(source).not.toContain('variant="link"')
    }
  })

  it('padroniza Parcelamentos e Declarações pela cápsula KPI de Simples Nacional', () => {
    const paths = [
      'app/components/monitoring/KpiStrip.vue',
      'app/pages/monitoring/installments.vue',
      'app/pages/monitoring/declarations.vue'
    ]

    for (const path of paths) {
      const source = readFileSync(resolve(process.cwd(), path), 'utf8')
      const start = source.indexOf('<ShellScrollableTabs')
      const markup = source.slice(start, source.indexOf('/>', start) + 2)

      expect(start, path).toBeGreaterThan(-1)
      expect(markup, path).toContain('size="md"')
      expect(markup, path).toContain('class="w-full min-w-0 max-w-full"')
      expect(markup, path).not.toContain('color=')
      expect(markup, path).not.toContain('variant=')
      expect(markup, path).not.toContain(':ui=')
    }

    const kpiSource = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/KpiStrip.vue'),
      'utf8'
    )
    const installmentsSource = readFileSync(
      resolve(process.cwd(), 'app/pages/monitoring/installments.vue'),
      'utf8'
    )
    const declarationsSource = readFileSync(
      resolve(process.cwd(), 'app/pages/monitoring/declarations.vue'),
      'utf8'
    )

    expect(kpiSource).toContain('badge: loadingPlaceholder ? \'…\' : count')
    expect(installmentsSource).toContain('badge: tabBadge(\'all\')')
    expect(installmentsSource).toContain('badge: tabBadge(item.code)')
    expect(declarationsSource).toContain('badge: tabBadge(t.value)')
  })

  it('simplifica o chrome da carteira Simples Nacional sem afetar outras carteiras', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/simples-mei/Portfolio.vue'),
      'utf8'
    )
    const moduleTable = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/ModuleTable.vue'),
      'utf8'
    )

    expect(source).toContain(':show-pending-search="false"')
    expect(source).not.toContain('show-synthetic-alert')
    expect(source).not.toContain('>\n          Regime\n        </p>')
    expect(source).not.toContain('class="w-full min-w-0"')
    expect(moduleTable).toContain('showPendingSearch: true')
    expect(moduleTable).not.toContain('showSyntheticAlert')
    expect(moduleTable).not.toContain('MonitoringSerproCoveragePanel')
    expect(moduleTable).not.toContain('fiscal-synthetic-alert')
  })
})
