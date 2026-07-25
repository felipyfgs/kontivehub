import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { MeUser } from '~/types/api'
import {
  canCreateWorkProcesses,
  canExecuteWorkTasks,
  canManageWorkCatalog
} from '~/utils/permissions'
import { workNavigationItems } from '~/utils/work-navigation'

const source = (...parts: string[]) => readFileSync(resolve(process.cwd(), ...parts), 'utf8')

describe('Work UI — gates canônicos de ação', () => {
  it('WorkTaskStatusSelect exige canExecuteWorkTasks e não fica acionável só com administer', () => {
    const select = source('app/components/work/WorkTaskStatusSelect.vue')
    expect(select).toContain('canExecuteWorkTasks')
    expect(select).toContain('!canExecute.value')
    expect(select).toContain('isDisabled')

    const workspace = source('app/components/work/WorkQueueWorkspace.vue')
    expect(workspace).toContain(':disabled="!canExecute"')
    expect(workspace).not.toContain(':disabled="!(canExecute || canAdmin)"')
    expect(workspace).toContain(':can-execute-tasks="canExecute"')

    const processes = source('app/pages/work/processes/index.vue')
    expect(processes).toContain(':disabled="!canExecute"')
    expect(processes).not.toContain(':disabled="!canBulk"')
    expect(processes).toContain(':can-execute-tasks="canExecute"')
    expect(processes).toContain('workProcessBulkCapabilities')
    expect(processes).toContain('canUpdateProcesses: canUpdateProcesses.value')
    expect(processes).toContain('v-if="canBulkTasks"')
  })

  it('bulk de tarefas só oferece execução com canExecuteTasks', () => {
    const bulk = source('app/components/work/WorkBulkActionsModal.vue')
    expect(bulk).toContain('canExecuteTasks')
    expect(bulk).toContain('if (props.canExecuteTasks)')
    expect(bulk).toContain('!taskActionItems.value.length')
    expect(bulk).toContain('initialWorkBulkScope')
    expect(bulk).toContain('canExecuteTasks: props.canExecuteTasks')
  })

  it('Rotinas separa catálogo (manage) de geração (create processes)', () => {
    const templates = source('app/pages/work/templates/index.vue')
    expect(templates).toContain('canManageWorkCatalog')
    expect(templates).toContain('canCreateWorkProcesses')
    expect(templates).toContain('canManageCatalog')
    expect(templates).toContain('canGenerateProcesses')
    expect(templates).toContain('v-if="canManageCatalog"')
    expect(templates).toContain('v-if="canGenerateProcesses"')
    expect(templates).toContain('if (!canGenerateProcesses.value) return')
    expect(templates).toContain('if (!canManageCatalog.value) return')

    const processes = source('app/pages/work/processes/index.vue')
    expect(processes).toContain('canUpdateProcesses = computed(() => canCreateWorkProcesses(me.value))')
    expect(processes).not.toContain('canManageWorkCatalog(me.value) || canCreateWorkProcesses(me.value)')
  })

  it('nav Rotinas aparece com create processes mesmo sem catalog.manage', () => {
    const operatorCreateOnly = {
      id: 10,
      role: 'OPERATOR',
      effective_permissions: ['work.view', 'work.processes.create']
    } as MeUser

    expect(canCreateWorkProcesses(operatorCreateOnly)).toBe(true)
    expect(canManageWorkCatalog(operatorCreateOnly)).toBe(false)
    expect(canExecuteWorkTasks(operatorCreateOnly)).toBe(false)

    const labels = workNavigationItems(operatorCreateOnly).map(item => item.label)
    expect(labels).toContain('Rotinas')

    const viewerOnly = {
      id: 11,
      role: 'VIEWER',
      effective_permissions: ['work.view']
    } as MeUser
    expect(workNavigationItems(viewerOnly).map(item => item.label)).not.toContain('Rotinas')
  })
})
