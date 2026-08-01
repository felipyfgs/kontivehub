import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  COMMUNICATION_FLOWS_PATH,
  communicationFlowEditorPath,
  communicationFlowPath
} from '~/utils/communication-routes'

const root = (...parts: string[]) => resolve(process.cwd(), ...parts)
const read = (...parts: string[]) => readFileSync(root(...parts), 'utf8')

describe('communication flow designer — N2/N3 surfaces', () => {
  it('expõe rota do editor e paths canônicos', () => {
    expect(COMMUNICATION_FLOWS_PATH).toBe('/communication/flows')
    expect(communicationFlowPath(3)).toBe('/communication/flows/3')
    expect(communicationFlowEditorPath(3)).toBe('/communication/flows/3/editor')
  })

  it('editor usa Vue Flow, composable sem Pinia e lock 409', () => {
    const editor = read('app/pages/communication/flows/[id]/editor.vue')
    const composable = read('app/composables/useFlowEditorDraft.ts')
    const canvas = read('app/components/communication/flows/EditorCanvas.client.vue')
    const list = read('app/components/communication/flows/EditorListMode.vue')
    const pkg = read('package.json')

    expect(pkg).toContain('"@vue-flow/core"')
    expect(editor).toContain('ShellPagePanel')
    expect(editor).toContain('useFlowEditorDraft')
    expect(editor).toContain('version_conflict')
    expect(editor).toContain('flow-editor-version-conflict')
    expect(editor).toContain('flow-editor-validate')
    expect(editor).toContain('flow-editor-save')
    expect(editor).toContain('flow-editor-publish')
    expect(editor).toContain('flow-editor-dry-run')
    expect(editor).toContain('flow-editor-preview')
    expect(editor).toContain('usePreferredReducedMotion')
    expect(editor).toContain('useMediaQuery')
    expect(editor).toContain('EditorListMode')
    expect(editor).not.toContain('defineStore')
    expect(editor).not.toContain('pinia')

    expect(composable).toContain('useFlowEditorDraft')
    expect(composable).not.toContain('pinia')
    expect(composable).not.toContain('defineStore')

    expect(canvas).toContain('@vue-flow/core')
    expect(canvas).toContain('readOnly')
    expect(canvas).toContain('reducedMotion')

    expect(list).toContain('flow-editor-list-mode')
    expect(list).toContain('role="listbox"')
    expect(list).toContain('Conectar')
  })

  it('detalhe exige confirmação de publish/enable e aponta ao editor', () => {
    const detail = read('app/pages/communication/flows/[id]/index.vue')
    const api = read('app/composables/api/createCommunicationApi.ts')

    expect(detail).toContain('communicationFlowEditorPath')
    expect(detail).toContain('communication-flow-open-editor')
    expect(detail).toContain('communication-flow-publish-modal')
    expect(detail).toContain('communication-flow-enable-modal')
    expect(detail).toContain('openEnable')
    expect(detail).toContain('communication-flow-runs-empty')
    expect(detail).toContain('communication-flow-versions')
    expect(detail).toContain('listRuns')
    expect(detail).toContain('loadRuns')
    expect(detail).toContain('communication-flow-toggle-json')
    expect(detail).not.toContain('defineStore')

    expect(api).toContain('/dry-run')
    expect(api).toContain('/preview')
    expect(api).toContain('/flow-runs/')
    expect(api).toContain('listRuns')
    expect(api).toContain('getRun')
  })

  it('lista permanece sem Vue Flow e sem /bots', () => {
    const list = read('app/pages/communication/flows/index.vue')
    const nav = read('app/utils/navigation.ts')
    expect(list).toContain('ShellDataTable')
    expect(list).not.toContain('@vue-flow')
    expect(list).not.toContain('/bots')
    expect(nav).toContain('COMMUNICATION_FLOWS_PATH')
    expect(nav).not.toContain('/communication/bots')
  })

  it('paleta só oferece nós allowlisted', () => {
    const palette = read('app/components/communication/flows/EditorPalette.vue')
    expect(palette).toContain('FLOW_NODE_TYPES')
    expect(palette).not.toContain('webhook')
    expect(palette).not.toContain('llm')
  })
})
