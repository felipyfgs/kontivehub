import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(import.meta.dirname, '../..')
const source = (path: string) => readFileSync(resolve(root, path), 'utf8')

describe('paleta contextual do Atendimento', () => {
  it('estende a única busca global por registro owner-scoped e limpa por sessão', () => {
    const dashboard = source('app/composables/useDashboard.ts')
    const layout = source('app/layouts/default.vue')
    const css = source('app/assets/css/main.css')
    const registry = source('app/utils/dashboard-contextual-command-registry.ts')

    expect(layout.match(/<UDashboardSearch\b/g)).toHaveLength(1)
    expect(layout).toContain('...contextualCommandGroups.value')
    expect(layout).toContain('modal: \'dashboard-search-layer\'')
    expect(css).toContain('[data-slot=\'overlay\']:has(+ .dashboard-search-layer)')
    expect(dashboard).toContain('watch(sessionEpoch')
    expect(dashboard).toContain('contextualCommands.clear()')
    expect(registry).toContain('registration.owner !== owner')
    expect(registry).toContain('registration.token !== token')
  })

  it('publica somente intenções locais e revalida antes de executar após o overlay', () => {
    const page = source('app/components/communication/WorkspacePage.vue')
    const composer = source('app/components/communication/Composer.vue')
    const timeline = source('app/components/communication/TimelinePanel.vue')
    const filters = source('app/components/communication/ConversationListFilters.vue')

    for (const commandId of [
      'communication-focus-search',
      'communication-focus-list',
      'communication-previous-conversation',
      'communication-next-conversation',
      'communication-toggle-context',
      'communication-focus-composer',
      'communication-view-open',
      'communication-view-unread',
      'communication-view-unassigned',
      'communication-new-conversation'
    ]) expect(page).toContain(commandId)

    expect(page).toContain('runAfterDashboardSearchClose')
    expect(page).toContain('document.activeElement?.closest(\'[role="dialog"]\')')
    expect(page).toContain('new MutationObserver')
    expect(page).toContain('searchDialog.isConnected')
    expect(page).toContain('requestAnimationFrame(() => requestAnimationFrame')
    expect(page).toContain('if (!isCommunicationWorkspacePath(route.path)) return')
    expect(page).toContain('if (workspace.selectedConversation.value) toggleContext()')
    expect(page).toContain('if (workspace.canView.value) applyQuickView(view.value)')
    expect(page).not.toContain('meta_k')
    expect(filters).toContain('defineExpose({ focusSearch })')
    expect(timeline).toContain('defineExpose({ focusComposer })')
    expect(composer).toContain('defineExpose({ focusInput })')
    expect(composer).toContain('querySelector(\'[data-communication-message-input]\')')
    expect(composer).toContain('markedElement?.querySelector(\'textarea\')')
    expect(composer).toContain('data-communication-message-input')
  })
})
