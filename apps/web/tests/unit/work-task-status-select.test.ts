import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

describe('WorkTaskStatusSelect', () => {
  it('monta selectKey com requiresEvidence e sem fluxo block modal', () => {
    const content = source('app/components/work/WorkTaskStatusSelect.vue')
    expect(content).toMatch(/selectKey[\s\S]*requiresEvidence/)
    expect(content).toContain('requiresEvidence')
    expect(content).not.toContain('blockReason')
    expect(content).not.toContain('blockOpen')
  })

  it('gateia mutação com canExecuteWorkTasks', () => {
    const content = source('app/components/work/WorkTaskStatusSelect.vue')
    expect(content).toContain('canExecuteWorkTasks')
    expect(content).toContain('isDisabled')
    expect(content).toContain('if (!canExecute.value) return')
  })
})
