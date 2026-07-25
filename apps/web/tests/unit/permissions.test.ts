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
    role: 'ADMIN'
  } as MeUser

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
  it('preferem chaves canônicas quando o payload traz effective_permissions', () => {
    const viewer = {
      id: 1,
      role: 'OPERATOR',
      effective_permissions: ['work.view']
    } as MeUser

    expect(canViewWork(viewer)).toBe(true)
    expect(canExecuteWorkTasks(viewer)).toBe(false)
    expect(canCreateWorkProcesses(viewer)).toBe(false)
    expect(canManageWorkCatalog(viewer)).toBe(false)
    expect(canAdministerWork(viewer)).toBe(false)
    expect(canExportWork(viewer)).toBe(false)
    expect(canDownloadWorkEvidence(viewer)).toBe(false)

    const executor = {
      id: 2,
      role: 'OPERATOR',
      effective_permissions: [
        'work.view',
        'work.tasks.execute',
        'work.processes.create',
        'work.exports.create',
        'work.evidence.download'
      ]
    } as MeUser

    expect(canExecuteWorkTasks(executor)).toBe(true)
    expect(canCreateWorkProcesses(executor)).toBe(true)
    expect(canExportWork(executor)).toBe(true)
    expect(canDownloadWorkEvidence(executor)).toBe(true)
    expect(canManageWorkCatalog(executor)).toBe(false)
    expect(canAdministerWork(executor)).toBe(false)
  })

  it('OPERATOR com effective_permissions sem chave de execução falha fail-closed', () => {
    const operatorWithoutExecute = {
      id: 3,
      role: 'OPERATOR',
      effective_permissions: ['work.view', 'work.processes.create']
    } as MeUser

    expect(canViewWork(operatorWithoutExecute)).toBe(true)
    expect(canCreateWorkProcesses(operatorWithoutExecute)).toBe(true)
    expect(canExecuteWorkTasks(operatorWithoutExecute)).toBe(false)
    expect(canManageWorkCatalog(operatorWithoutExecute)).toBe(false)
  })

  it('ADMIN com work.catalog.manage habilita catálogo; sem a chave nega', () => {
    const adminWithCatalog = {
      id: 4,
      role: 'ADMIN',
      effective_permissions: ['work.view', 'work.catalog.manage', 'work.administer']
    } as MeUser

    expect(canManageWorkCatalog(adminWithCatalog)).toBe(true)
    expect(canAdministerWork(adminWithCatalog)).toBe(true)
    expect(canExecuteWorkTasks(adminWithCatalog)).toBe(false)

    const adminWithoutCatalog = {
      id: 5,
      role: 'ADMIN',
      effective_permissions: ['work.view', 'work.tasks.execute']
    } as MeUser

    expect(canManageWorkCatalog(adminWithoutCatalog)).toBe(false)
    expect(canExecuteWorkTasks(adminWithoutCatalog)).toBe(true)
  })

  it('sem effective_permissions usa fallback legado por papel', () => {
    const legacyViewer = { id: 6, role: 'VIEWER' } as MeUser
    expect(canViewWork(legacyViewer)).toBe(true)
    expect(canExecuteWorkTasks(legacyViewer)).toBe(false)
    expect(canCreateWorkProcesses(legacyViewer)).toBe(false)
    expect(canManageWorkCatalog(legacyViewer)).toBe(false)
    expect(canAdministerWork(legacyViewer)).toBe(false)
    expect(canExportWork(legacyViewer)).toBe(false)
    expect(canDownloadWorkEvidence(legacyViewer)).toBe(false)

    const legacyOperator = { id: 7, role: 'OPERATOR' } as MeUser
    expect(canViewWork(legacyOperator)).toBe(true)
    expect(canExecuteWorkTasks(legacyOperator)).toBe(true)
    expect(canCreateWorkProcesses(legacyOperator)).toBe(true)
    expect(canExportWork(legacyOperator)).toBe(true)
    expect(canDownloadWorkEvidence(legacyOperator)).toBe(true)
    expect(canManageWorkCatalog(legacyOperator)).toBe(false)
    expect(canAdministerWork(legacyOperator)).toBe(false)

    const legacyAdmin = { id: 8, role: 'ADMIN' } as MeUser
    expect(canViewWork(legacyAdmin)).toBe(true)
    expect(canExecuteWorkTasks(legacyAdmin)).toBe(true)
    expect(canCreateWorkProcesses(legacyAdmin)).toBe(true)
    expect(canManageWorkCatalog(legacyAdmin)).toBe(true)
    expect(canAdministerWork(legacyAdmin)).toBe(true)
    expect(canExportWork(legacyAdmin)).toBe(true)
    expect(canDownloadWorkEvidence(legacyAdmin)).toBe(true)
  })
})
