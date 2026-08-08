import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(import.meta.dirname, '../../..')
const registry = resolve(root, '.nuxt/components.d.ts')
const workspace = resolve(root, 'app/composables/useCommunicationWorkspace.ts')
const communicationTypes = [
  'app/types/communication/index.ts',
  'app/types/communication/automation.ts',
  'app/types/communication/contacts.ts',
  'app/types/communication/conversations.ts',
  'app/types/communication/flows.ts',
  'app/types/communication/inboxes.ts',
  'app/types/communication/messages.ts',
  'app/types/communication/quick-responses.ts',
  'app/types/communication/realtime.ts',
  'app/types/communication/shared-content.ts'
]
const forbiddenGeneratedNames = [
  'FlowsFlow',
  'ContactsContact',
  'QuickResponsesQuickResponse',
  'PgdasdPgdasd'
]
const forbiddenSourcePaths = [
  'components/communication/flows/FlowBindingsSection.vue',
  'components/communication/flows/FlowCatalogModals.vue',
  'components/communication/flows/FlowCatalogTable.vue',
  'components/communication/flows/FlowDraftSection.vue',
  'components/communication/flows/FlowEditorCanvas.client.vue',
  'components/communication/flows/FlowEditorInspector.vue',
  'components/communication/flows/FlowEditorListMode.vue',
  'components/communication/flows/FlowEditorPalette.vue',
  'components/communication/flows/FlowMetadataSection.vue',
  'components/communication/flows/FlowRunsUnavailable.vue',
  'components/communication/flows/FlowVersionsSection.vue',
  'components/communication/contacts/ContactActions.vue',
  'components/communication/contacts/ContactContext.vue',
  'components/communication/quick-responses/QuickResponseEditorModal.vue',
  'components/communication/quick-responses/QuickResponseDuplicateModal.vue',
  'components/communication/quick-responses/QuickResponseDeactivateModal.vue',
  'components/monitoring/pgdasd/PgdasdHistoryPeriodGrid.vue',
  'components/communication/CommunicationWorkspacePage.vue'
]

function listVersionedFiles(): string[] {
  try {
    return execFileSync('git', ['ls-files'], { cwd: root, encoding: 'utf8' })
      .split('\n')
      .filter(Boolean)
  } catch {
    // Sem repo montado (container com volume parcial): varre o filesystem,
    // ignorando artefatos que o .gitignore também ignora.
    const excluded = new Set([
      'node_modules', '.nuxt', '.output', 'dist', '.playwright',
      '.playwright-cli', '.pnpm-store', 'playwright-report', 'nuxt-fixture'
    ])
    const out: string[] = []
    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir)) {
        if (excluded.has(entry)) continue
        const absolute = resolve(dir, entry)
        if (statSync(absolute).isDirectory()) {
          walk(absolute)
          continue
        }
        out.push(relative(root, absolute))
      }
    }
    walk(root)
    return out
  }
}

describe('identificadores do registry de componentes', () => {
  it('não reintroduz basenames redundantes no filesystem', () => {
    expect(forbiddenSourcePaths.filter(path => existsSync(resolve(root, 'app', path)))).toEqual([])
  })

  it('valida os nomes efetivamente gerados pelo Nuxt', () => {
    expect(existsSync(registry)).toBe(true)

    const components = readFileSync(registry, 'utf8')
    expect(forbiddenGeneratedNames.filter(name => components.includes(name))).toEqual([])
    expect(components).toContain('CommunicationFlowsCatalogTable')
    expect(components).toContain('CommunicationContactsContext')
    expect(components).toContain('CommunicationQuickResponsesEditorModal')
    expect(components).toContain('MonitoringPgdasdHistoryPeriodGrid')
  })

  it('não repete Communication nos símbolos privados do workspace', () => {
    const source = readFileSync(workspace, 'utf8')
    const forbiddenSymbols = [
      'CommunicationWorkspaceNavigationState',
      'CommunicationLifecycleRequest',
      'CommunicationSynchronizationRequest',
      'communicationWorkspaceNavigationDefaults',
      'normalizeCommunicationWorkspaceNavigation'
    ]

    expect(forbiddenSymbols.filter(name => source.includes(name))).toEqual([])
  })

  it('mantém tipos canônicos curtos e separados por subdomínio', () => {
    const symbols = communicationTypes.flatMap((path) => {
      const source = readFileSync(resolve(root, path), 'utf8')
      return source.match(/\bCommunication[A-Z]\w*\b/g) || []
    })

    expect(symbols).toEqual([])
  })

  it('não mantém nomenclaturas transitórias em arquivos versionados', () => {
    const forbidden = /\blegacy\b|\blegad\b/i
    expect(forbidden.test('legado')).toBe(false)
    expect(forbidden.test('legados')).toBe(false)
    const technicalLegacy = ['leg', 'acy'].join('')
    const technicalLegad = ['leg', 'ad'].join('')
    expect(forbidden.test(technicalLegacy)).toBe(true)
    expect(forbidden.test(technicalLegad)).toBe(true)
    const files = listVersionedFiles()
      .filter(path => !path.startsWith('app/types/generated/'))
      .filter(path => !path.includes('node_modules/') && !path.includes('.playwright/'))
      .filter(path => existsSync(resolve(root, path)))

    const violations = files.flatMap((path) => {
      const source = readFileSync(resolve(root, path), 'utf8')
      return forbidden.test(path) || forbidden.test(source) ? [path] : []
    })

    expect(violations).toEqual([])
  })
})
