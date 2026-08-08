import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = (path: string) => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('runtime frontend fail-closed', () => {
  it('condiciona realtime a sessão, permissão e tenant e desmonta ao perder contexto', () => {
    const plugin = source('app/plugins/communication-realtime.client.ts')

    expect(plugin).toContain('canViewCommunication(current)')
    expect(plugin).toContain('current?.context_status === \'ok\'')
    expect(plugin).toContain('current.current_tenant?.id != null')
    expect(plugin).toContain('teardown()')
    expect(plugin).toContain('import(\'laravel-echo\')')
    expect(plugin).toContain('import(\'pusher-js\')')
    expect(plugin).toContain('ensureInboxChannel(inboxId)')
    expect(plugin).toContain('if (!active.value) return noSubscription()')
    expect(plugin).not.toContain('if (!echo || !active.value)')
    expect(plugin).toContain('subscribedInboxes.clear()')
    expect(plugin).toContain('subscribedTenants.clear()')
    expect(plugin).toContain('window.addEventListener(\'online\', retryWhenOnline)')
    expect(plugin).toContain('retryTimer = setTimeout')
    expect(plugin).toContain('pusher?.disconnect()')
    expect(plugin).toContain('pusher = null')
    expect(plugin).not.toContain('import Echo from \'laravel-echo\'')
  })

  it('executa os guardas de rota antes dos loaders de comunicação', () => {
    const guard = source('app/utils/communication-route-access.ts')

    expect(guard).toContain('canViewCommunication(identity)')
    expect(guard).toContain('identity?.context_status !== \'ok\'')
    expect(guard).toContain('!identity.current_tenant')
  })

  it('ignora a lista de tenants que resolve depois da troca de sessão', () => {
    const page = source('app/pages/admin/fiscal-modules.vue')

    expect(page).toContain('let tenantListLoadSeq = 0')
    expect(page).toContain('const seq = ++tenantListLoadSeq')
    expect(page).toContain('epoch !== sessionEpoch.value')
    expect(page).toContain('seq === tenantListLoadSeq && epoch === sessionEpoch.value')
  })

  it('protege histórico fiscal contra resposta de tenant anterior', () => {
    const history = source('app/components/monitoring/PgdasdDasHistoryModal.vue')

    expect(history).toContain('const { sessionEpoch } = useDashboard()')
    expect(history).toContain('epoch === sessionEpoch.value')
    expect(history).toContain('watch(sessionEpoch')
    expect(history).toContain('requestGeneration += 1')
  })
})
