import type { MeUser } from '~/types/api'
import { describe, expect, it } from 'vitest'
import {
  canAdministerWork,
  canCreateWorkProcesses,
  canDownloadWorkEvidence,
  canExecuteWorkTasks,
  canExportWork,
  canManageWorkCatalog,
  canViewWork,
  unwrapMeUser
} from '~/utils/permissions'

describe('identidade da sessão', () => {
  const user = {
    id: 42,
    effective_permissions: []
  } as unknown as MeUser

  it('aceita tanto o usuário direto quanto o envelope da API', () => {
    expect(unwrapMeUser(user)).toBe(user)
    expect(unwrapMeUser({ data: user })).toBe(user)
  })

  it('trata respostas não estruturadas como sessão indisponível', () => {
    expect(unwrapMeUser('<br /> Fatal error')).toBeNull()
    expect(unwrapMeUser(500)).toBeNull()
    expect(unwrapMeUser({ data: '<br /> Fatal error' })).toBeNull()
    expect(unwrapMeUser(null)).toBeNull()
  })
})

describe('helpers Work (effective_permissions)', () => {
  it('autoriza somente pelas permissões efetivas', () => {
    const viewer = {
      id: 1,
      effective_permissions: ['work.view']
    } as unknown as MeUser

    expect(canViewWork(viewer)).toBe(true)
    expect(canExecuteWorkTasks(viewer)).toBe(false)
    expect(canCreateWorkProcesses(viewer)).toBe(false)
    expect(canManageWorkCatalog(viewer)).toBe(false)
    expect(canAdministerWork(viewer)).toBe(false)
    expect(canExportWork(viewer)).toBe(false)
    expect(canDownloadWorkEvidence(viewer)).toBe(false)

    const executor = {
      id: 2,
      effective_permissions: [
        'work.view',
        'work.tasks.execute',
        'work.processes.create',
        'work.exports.create',
        'work.evidence.download'
      ]
    } as unknown as MeUser

    expect(canExecuteWorkTasks(executor)).toBe(true)
    expect(canCreateWorkProcesses(executor)).toBe(true)
    expect(canExportWork(executor)).toBe(true)
    expect(canDownloadWorkEvidence(executor)).toBe(true)
    expect(canManageWorkCatalog(executor)).toBe(false)
    expect(canAdministerWork(executor)).toBe(false)
  })

  it('nega execução quando a permissão efetiva não está presente', () => {
    const memberWithoutExecute = {
      id: 3,
      effective_permissions: ['work.view', 'work.processes.create']
    } as unknown as MeUser

    expect(canViewWork(memberWithoutExecute)).toBe(true)
    expect(canCreateWorkProcesses(memberWithoutExecute)).toBe(true)
    expect(canExecuteWorkTasks(memberWithoutExecute)).toBe(false)
    expect(canManageWorkCatalog(memberWithoutExecute)).toBe(false)
  })

  it('separa administração do catálogo da execução de tarefas', () => {
    const catalogManager = {
      id: 4,
      effective_permissions: ['work.view', 'work.catalog.manage', 'work.administer']
    } as unknown as MeUser

    expect(canManageWorkCatalog(catalogManager)).toBe(true)
    expect(canAdministerWork(catalogManager)).toBe(true)
    expect(canExecuteWorkTasks(catalogManager)).toBe(false)

    const taskExecutor = {
      id: 5,
      effective_permissions: ['work.view', 'work.tasks.execute']
    } as unknown as MeUser

    expect(canManageWorkCatalog(taskExecutor)).toBe(false)
    expect(canExecuteWorkTasks(taskExecutor)).toBe(true)
  })

  it('falha fechado quando effective_permissions não existe', () => {
    const member = { id: 6 } as unknown as MeUser
    expect(canViewWork(member)).toBe(false)
    expect(canExecuteWorkTasks(member)).toBe(false)
    expect(canCreateWorkProcesses(member)).toBe(false)
    expect(canManageWorkCatalog(member)).toBe(false)
    expect(canAdministerWork(member)).toBe(false)
    expect(canExportWork(member)).toBe(false)
    expect(canDownloadWorkEvidence(member)).toBe(false)
  })
})
