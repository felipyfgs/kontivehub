import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('ações manuais por papel', () => {
  it('não renderiza o controle de execução quando o papel não pode atualizar', () => {
    const component = readFileSync(
      resolve(process.cwd(), 'app/components/monitoring/ManualConsultExplorer.vue'),
      'utf8'
    )
    const runButton = component.match(
      /<UButton\s+v-if="canTriggerSync"[\s\S]*?data-testid="manual-consult-run"[\s\S]*?\/>/
    )

    expect(runButton).not.toBeNull()
    expect(runButton?.[0]).not.toContain('!canTriggerSync')
  })
})
