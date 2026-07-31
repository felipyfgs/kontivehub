import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('master-detail shell navbar gate', () => {
  it('migrates only the authorized communication master navbar', () => {
    const page = source('app/components/communication/CommunicationWorkspacePage.vue')
    const master = page.split('id="communication-list"')[1]?.split('</UDashboardPanel>')[0] ?? ''

    expect(master.match(/<ShellPageNavbar\b/g)).toHaveLength(1)
    expect(master).not.toContain('<UDashboardNavbar')
    expect(master).not.toContain('<UDashboardSidebarCollapse')
    expect(master).toMatch(
      /<template #trailing>[\s\S]*?<UBadge[\s\S]*?:label="String\(workspace\.conversationsTotal\.value\)"[\s\S]*?<\/template>/
    )
    expect(master).toContain('communication-realtime-status')
    expect(master).toContain('role="status"')
    expect(master).toContain('Atualização em tempo real')
    expect(master).toContain('communication-navbar-more')
    expect(page).toContain('label: \'Sincronizar conversas\'')
    expect(page).toContain('label: \'Administrar comunicação\'')
    expect(master).toContain(':default-size="24"')
    expect(master).toContain(':min-size="20"')
    expect(master).toContain(':max-size="32"')
    expect(page).toContain('communication-mobile-timeline')
    expect(page).toContain('communication-context-slideover')
    expect(page).toContain('focusConversation')
  })

  it('keeps one work navbar and the shared toolbar for all views', () => {
    const chrome = source('app/components/work/WorkQueueChrome.vue')
    const workspace = source('app/components/work/WorkQueueWorkspace.vue')

    expect(chrome.match(/<ShellPageNavbar\b/g)).toHaveLength(1)
    expect(chrome).not.toContain('<UDashboardNavbar')
    expect(chrome).not.toContain('<UDashboardSidebarCollapse')
    expect(chrome.match(/<UDashboardToolbar\b/g)).toHaveLength(1)
    expect(chrome).toContain('work-queue-total')
    expect(chrome).toContain('work-queue-view-toggle')
    expect(chrome).toContain('work-queue-detail-toggle')
    expect(workspace).toContain('detailPaneVisible')
    expect(workspace).toContain('USlideover')
    expect(workspace).toContain('focusQueueItem')
  })

  it('keeps mailbox navbar above the unchanged resizable split', () => {
    const page = source('app/pages/monitoring/mailbox.vue')
    const header = page.split('Chrome Fiscal em largura total')[1]?.split('mailbox-monitoring-collapsible')[0] ?? ''

    expect(header.match(/<ShellPageNavbar\b/g)).toHaveLength(1)
    expect(header).not.toContain('<UDashboardNavbar')
    expect(header).not.toContain('<UDashboardSidebarCollapse')
    expect(header).toContain('mailbox-detail-toggle')
    expect(header).toContain('mailbox-alerts-trigger')
    expect(page).toContain('id="mailbox-list"')
    expect(page).toContain(':resizable="detailPaneVisible"')
    expect(page).toContain('mailbox-detail-pane')
    expect(page).toContain('<NuxtPage')
    expect(page).toContain('<USlideover')
    expect(page).toContain('focusMessage')
  })

  it('does not add a public master-detail wrapper', () => {
    expect(existsSync(resolve(process.cwd(), 'app/components/shell/MasterDetailWorkspace.vue'))).toBe(false)
  })
})
