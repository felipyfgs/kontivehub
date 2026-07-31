import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)

/** Listas N1 com campos primários de cards configurados. */
const MOBILE_CARD_SURFACES = [
  'app/pages/exports.vue',
  'app/pages/syncs.vue',
  'app/pages/closing.vue',
  'app/pages/health.vue',
  'app/pages/docs/imports/index.vue',
  'app/pages/docs/imports/[id].vue',
  'app/pages/work/templates/index.vue',
  'app/pages/work/processes/index.vue',
  'app/pages/admin/tenants/index.vue',
  'app/pages/admin/serpro/catalog.vue',
  'app/pages/admin/serpro/contracts.vue',
  'app/pages/admin/serpro/usage.vue',
  'app/components/settings/TenantUsagePage.vue',
  'app/components/docs/Catalog.vue',
  'app/components/docs/ByClient.vue',
  'app/components/docs/Detail.vue',
  'app/components/clients/CatalogList.vue',
  'app/components/monitoring/ModuleDataTable.vue'
] as const

/** Mestre–detalhe que devem usar slideover/stack em &lt; lg. */
const SPLIT_SURFACES = [
  'app/pages/monitoring/mailbox.vue',
  'app/components/work/WorkQueueWorkspace.vue',
  'app/pages/work/calendar.vue'
] as const

function walkVueFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    const st = statSync(full)
    if (st.isDirectory()) {
      walkVueFiles(full, out)
      continue
    }
    if (entry.endsWith('.vue')) out.push(full)
  }
  return out
}

describe('painel-responsivo-mobile-gate', () => {
  it('kit shell expõe caminho de cards mobile', () => {
    const dataTable = readFileSync(root('app/components/shell/DataTable.vue'), 'utf8')
    const mobileCards = readFileSync(root('app/components/shell/MobileCards.vue'), 'utf8')
    const footer = readFileSync(root('app/components/shell/TableFooter.vue'), 'utf8')
    const tableUi = readFileSync(root('app/utils/table-ui.ts'), 'utf8')

    expect(dataTable).toContain('ShellMobileCards')
    expect(dataTable).toContain('mobileCards')
    expect(mobileCards).toContain('primaryColumnId')
    expect(tableUi).toContain('flex-col')
    expect(tableUi).toContain('sm:flex-row')
    expect(footer).toContain('smaller(\'sm\')')
    expect(footer).toContain('list-table-footer-controls')
  })

  it('catálogo N1 configura primary/status/summary nos ShellDataTable', () => {
    for (const rel of MOBILE_CARD_SURFACES) {
      const source = readFileSync(root(rel), 'utf8')
      expect(source, rel).toContain('ShellDataTable')
      expect(source, `${rel} primary`).toMatch(/primary-column-id|primaryColumnId/)
      expect(source, `${rel} status ou summary`).toMatch(
        /status-column-id|statusColumnId|summary-column-ids|summaryColumnIds/
      )
    }
  })

  it('processos usa ShellDataTable com árvore inline no modo Cliente (sem slideover)', () => {
    const page = readFileSync(root('app/pages/work/processes/index.vue'), 'utf8')

    expect(page).toContain('ShellDataTable')
    expect(page).toContain('work-processes-table')
    expect(page).toMatch(/primary-column-id="(title|label)"/)
    expect(page).toContain('summary-column-ids')
    expect(page).toContain('work-process-group-tree')
    expect(page).toContain('expandAllGroupKeys')
    expect(page).toContain(':mobile-cards="false"')
    expect(page).not.toContain('USlideover')
    expect(page).not.toContain('Processos do cliente')
    expect(page).not.toContain('WorkProcessAccordionList')
    expect(page).not.toContain('overflow-x-auto')
  })

  it('splits N2 usam breakpoint lg + slideover', () => {
    for (const rel of SPLIT_SURFACES) {
      const source = readFileSync(root(rel), 'utf8')
      expect(source, rel).toMatch(/smaller\(['"]lg['"]\)|hidden lg:flex|lg:hidden/)
      expect(source, `${rel} slideover`).toContain('USlideover')
    }
  })

  it('layout default expõe slot direto no UDashboardGroup (master–detalhe)', () => {
    const layout = readFileSync(root('app/layouts/default.vue'), 'utf8')
    expect(layout).toContain('<UDashboardGroup')
    expect(layout).toMatch(/<UDashboardSearch[^/]*\/>\s*<slot\s*\/>/s)
    expect(layout).not.toMatch(/flex-col">\s*<slot/)
  })

  it('fila de tarefas expõe dualidade Fila|Lista|Kanban com ShellDataTable', () => {
    const workspace = readFileSync(root('app/components/work/WorkQueueWorkspace.vue'), 'utf8')
    const chrome = readFileSync(root('app/components/work/WorkQueueChrome.vue'), 'utf8')
    expect(workspace.match(/<WorkQueueChrome/g)).toHaveLength(1)
    expect(chrome.match(/work-queue-view-toggle/g)).toHaveLength(1)
    expect(chrome).toContain('value: \'fila\'')
    expect(chrome).toContain('value: \'lista\'')
    expect(chrome).toContain('value: \'kanban\'')
    expect(workspace).toContain('USlideover')
    expect(workspace).toContain('ShellDataTable')
    expect(workspace).toContain('work-queue-table')
    expect(workspace).toContain('WorkKanbanBoard')
    expect(workspace).toContain('@update:view="setQueueView"')
    expect(workspace).toContain('primary-column-id="title"')
  })

  it('fila restaura mestre–detalhe sem auto-select; mailbox segue densificada', () => {
    const workspace = readFileSync(root('app/components/work/WorkQueueWorkspace.vue'), 'utf8')
    const chrome = readFileSync(root('app/components/work/WorkQueueChrome.vue'), 'utf8')
    const mailbox = readFileSync(root('app/pages/monitoring/mailbox.vue'), 'utf8')
    expect(workspace).toContain('detailOpen')
    expect(chrome).toContain('work-queue-detail-toggle')
    expect(chrome).toContain('v-if="showDetailToggle"')
    expect(workspace).toContain('detailPaneVisible')
    expect(chrome).toContain('i-lucide-panel-right')
    expect(workspace).toContain('focusQueueItem')
    expect(workspace).toContain('itemRefs.value[id]?.focus()')
    expect(workspace).not.toContain('suppressAutoSelect')
    expect(workspace).not.toContain('await select(items.value[0]')
    expect(mailbox).toContain('detailOpen')
    expect(mailbox).toContain('mailbox-detail-toggle')
    expect(mailbox).toContain('mailbox-monitoring-collapsible')
    expect(mailbox).toContain('detailPaneVisible')
  })

  it('conta segue arquétipo settings (toolbar UNavigationMenu + NuxtPage)', () => {
    const conta = readFileSync(root('app/pages/conta.vue'), 'utf8')
    expect(conta).toContain('ShellSettingsShell')
    expect(conta).toContain('UNavigationMenu')
    expect(conta).toContain('account-section-navigation')
    expect(conta).toContain('<NuxtPage')
    expect(conta).toContain('width="comfortable"')
    expect(conta).not.toContain('AccountDetailTabNav')
  })

  it('detalhe do cliente segue layout master (header + abas + aside + NuxtPage)', () => {
    const client = readFileSync(root('app/pages/clients/[id].vue'), 'utf8')
    expect(client).toContain('ShellPagePanel')
    expect(client).toContain('UNavigationMenu')
    expect(client).toContain('client-section-navigation')
    expect(client).not.toContain('client-hub-navigation')
    expect(client).toContain('<NuxtPage')
    expect(client).toContain('ClientsIdentityHeader')
    expect(client).toContain('ClientsDetailAside')
    expect(client).not.toContain('ClientDetailTabNav')
  })

  it('detalhe fiscal do cliente usa rail fino com expand no hover + slideover', () => {
    const fiscal = readFileSync(root('app/pages/monitoring/clients/[clientId].vue'), 'utf8')
    const aside = readFileSync(root('app/components/monitoring/ClientFiscalAside.vue'), 'utf8')
    expect(fiscal).toContain('monitoring-client-nav-panel')
    expect(fiscal).toContain('MonitoringClientFiscalAside')
    expect(fiscal).toContain('w-14')
    expect(fiscal).toContain('w-52')
    expect(fiscal).toContain('onNavEnter')
    expect(fiscal).toContain('@mouseenter="onNavEnter"')
    expect(fiscal).toContain('USlideover')
    expect(fiscal).toContain('monitoring-client-nav-slideover')
    expect(fiscal).not.toContain('ShellSettingsShell')
    expect(fiscal).not.toContain('SectionNavigation')
    expect(fiscal).not.toContain('section-nav-subtabs')
    expect(aside).toContain('UNavigationMenu')
    expect(aside).toContain('orientation="vertical"')
    expect(aside).toContain('monitoring-client-section-navigation')
  })

  it('folhas do cliente e settings Conta usam ShellSectionHeader (chrome settings)', () => {
    const leafPages = [
      'app/pages/clients/[id]/cadastro.vue',
      'app/pages/clients/[id]/contato.vue',
      'app/pages/clients/[id]/departamento.vue',
      'app/pages/clients/[id]/dados-adicionais.vue',
      'app/pages/clients/[id]/observacoes.vue',
      'app/pages/clients/[id]/contratos.vue',
      'app/components/settings/TenantTeamPage.vue',
      'app/components/settings/TenantDepartmentsPage.vue',
      'app/components/settings/TenantSubscriptionPage.vue',
      'app/components/settings/TenantUsagePage.vue'
    ]
    for (const rel of leafPages) {
      const source = readFileSync(root(rel), 'utf8')
      expect(source, rel).toContain('ShellSectionHeader')
    }

    const team = readFileSync(root('app/components/settings/TenantTeamPage.vue'), 'utf8')
    expect(team).toContain('ShellFilterToolbarLite')
  })

  it('organização Nuxt UI: abas master + accordion cadastro/adicionais/escritório', () => {
    const registration = readFileSync(root('app/components/clients/Registration.vue'), 'utf8')
    expect(registration).toContain('ShellPanelAccordion')
    expect(registration).toContain('panel === \'all\'')
    expect(registration).not.toContain('ClientsForm')
    expect(registration).not.toContain('startEditing')

    const detailShell = readFileSync(root('app/pages/clients/[id].vue'), 'utf8')
    expect(detailShell).toContain('ClientsFormModal')
    expect(detailShell).toContain('openClientEdit')
    expect(detailShell).not.toContain('registrationEditRequested')

    const cadastro = readFileSync(root('app/pages/clients/[id]/cadastro.vue'), 'utf8')
    expect(cadastro).toContain('client-cadastro-refresh')
    expect(cadastro).toContain('startRefreshLookup')
    expect(cadastro).toContain('ClientsRegistrationRefreshModal')
    expect(cadastro).toContain('api.cnpj.lookup')
    expect(cadastro).not.toMatch(/refreshRegistration\(item\.value\.id\)\s*$/m)

    const refreshModal = readFileSync(root('app/components/clients/RegistrationRefreshModal.vue'), 'utf8')
    expect(refreshModal).toContain('ShellFormModal')
    expect(refreshModal).toContain('client-refresh-diff')
    expect(refreshModal).toContain('review-mode')

    const adicionais = readFileSync(root('app/components/clients/AdditionalDataPanel.vue'), 'utf8')
    expect(adicionais).toContain('ShellPanelAccordion')
    expect(adicionais).toContain('USwitch')

    const tenant = readFileSync(root('app/components/settings/TenantSettingsPanel.vue'), 'utf8')
    expect(tenant).toContain('ShellPanelAccordion')
    expect(tenant).toContain('SettingsTenantCredentialSection')
    expect(tenant).toContain('refreshIntegration')
    expect(tenant).not.toContain('settings-onboarding-status')
    expect(tenant).not.toContain('UStepper')
    expect(tenant).not.toContain('consentimento')

    const credential = readFileSync(root('app/components/settings/TenantCredentialSection.vue'), 'utf8')
    expect(credential).toContain('Atualizar integração')
    expect(credential).toContain('settings-credential-refresh-integration')

    const tabs = readFileSync(root('app/utils/client-detail-tabs.ts'), 'utf8')
    expect(tabs).toContain('\'contato\'')
    expect(tabs).toContain('\'departamento\'')
    expect(tabs).toContain('\'dados-adicionais\'')
    expect(tabs).toContain('\'observacoes\'')
    expect(tabs).not.toContain('value: \'fiscal\'')
  })

  it('DocsWorkspace permanece min-w-0 e detalhe em modal', () => {
    const workspace = readFileSync(root('app/components/docs/Workspace.vue'), 'utf8')
    expect(workspace).toContain('min-w-0')
    expect(workspace).toContain('DocsDetailModal')
    expect(workspace).toContain('DocsCatalog')
  })

  it('pages autenticadas com chrome canônico incluem collapse da sidebar', () => {
    const pagesDir = root('app/pages')
    const files = walkVueFiles(pagesDir)
    const authenticatedCandidates = files.filter((full) => {
      const rel = relative(root('app'), full).replace(/\\/g, '/')
      if (rel.includes('/two-factor')) return false
      const base = rel.split('/').pop() || ''
      // Auth/public surfaces sem shell autenticado
      if ([
        'login.vue',
        'first-access.vue',
        'activate.vue',
        'onboarding.vue',
        'two-factor-challenge.vue'
      ].includes(base)) {
        return false
      }
      const source = readFileSync(full, 'utf8')
      // Só pages que montam navbar ou painel próprio.
      const hasChrome = /ShellPageNavbar|UDashboardNavbar|UDashboardPanel|ShellPagePanel|ShellSettingsShell/.test(source)
      if (!hasChrome) return false
      return true
    })

    expect(authenticatedCandidates.length).toBeGreaterThan(10)

    const missing: string[] = []
    for (const full of authenticatedCandidates) {
      const source = readFileSync(full, 'utf8')
      const hasCollapse = source.includes('UDashboardSidebarCollapse')
        || source.includes('ShellPageNavbar') // ShellPageNavbar já embute collapse
        || source.includes('ShellSettingsShell') // SettingsShell → ShellPageNavbar
      if (!hasCollapse) {
        missing.push(relative(root('app'), full).replace(/\\/g, '/'))
      }
    }

    expect(missing, `Pages sem collapse: ${missing.join(', ')}`).toEqual([])
  })
})
