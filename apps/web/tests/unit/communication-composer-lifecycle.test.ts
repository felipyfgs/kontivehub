import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  canRetryComposerLifecycle,
  canTransitionComposerLifecycle,
  composerLifecycleAnnouncement,
  composerLifecycleCopy,
  composerLifecycleProgress
} from '~/utils/communication-composer-lifecycle'

describe('communication composer lifecycle', () => {
  it('mantém a entrega monotônica e impede regressão de recibos', () => {
    expect(canTransitionComposerLifecycle('validating', 'uploading')).toBe(true)
    expect(canTransitionComposerLifecycle('queued', 'read')).toBe(true)
    expect(canTransitionComposerLifecycle('queued', 'failed')).toBe(true)
    expect(canTransitionComposerLifecycle('failed', 'queued')).toBe(false)
    expect(canTransitionComposerLifecycle('delivered', 'sent')).toBe(false)
    expect(canTransitionComposerLifecycle('read', 'delivered')).toBe(false)
  })

  it('permite retry somente para filhos que falharam', () => {
    expect(canRetryComposerLifecycle('failed')).toBe(true)
    expect(canRetryComposerLifecycle('queued')).toBe(false)
    expect(canRetryComposerLifecycle('delivered')).toBe(false)
    expect(canRetryComposerLifecycle('read')).toBe(false)
  })

  it('explica envio parcial sem reenviar itens aceitos', () => {
    const copy = composerLifecycleCopy({ state: 'partially_sent' })
    expect(copy.cause).toContain('Parte do lote')
    expect(copy.impact).toContain('aceitos não serão reenviados')
    expect(copy.nextAction).toContain('somente os itens com falha')
  })

  it('usa progresso determinado e anuncia apenas mudanças relevantes', () => {
    expect(composerLifecycleProgress({ state: 'uploading', progress: 120 })).toBe(99)
    expect(composerLifecycleProgress({ state: 'queued' })).toBe(100)
    expect(composerLifecycleAnnouncement('sent', { id: '1', label: 'Contrato.pdf', state: 'delivered' })).toBeNull()
    expect(composerLifecycleAnnouncement('uploading', { id: '1', label: 'Contrato.pdf', state: 'failed' })).toContain('Tentar novamente')
  })

  it('expõe texto, ícone, aria-live e alvo móvel para cada ação', () => {
    const component = readFileSync(resolve(process.cwd(), 'app/components/communication/ComposerMediaLifecycle.vue'), 'utf8')
    expect(component).toContain('role="status"')
    expect(component).toContain('aria-live="polite"')
    expect(component).toContain('UIcon')
    expect(component).toContain('min-h-11')
    expect(component).toContain('canRetryComposerLifecycle(item.state)')
    expect(component).toContain('motion-reduce:transition-none')
  })
})
