import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import WorkQueueListItem from '../../app/components/work/WorkQueueListItem.vue'
import type { WorkTaskSummary } from '../../app/types/work'
import { restoreWorkSelectionFocus } from '../../app/utils/work-focus'

let wrapper: VueWrapper | null = null

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.replaceChildren()
})

const item = {
  id: 42,
  title: 'Apurar tributos',
  status: 'A_FAZER',
  lock_version: 1,
  is_critical: false,
  requires_evidence: false,
  risks: [],
  process: {
    id: 9,
    title: 'Fechamento mensal',
    client: { id: 7, name: 'Empresa Exemplo' }
  }
} as WorkTaskSummary

describe('WorkQueueListItem — teclado e foco', () => {
  it('seleciona a tarefa por Enter e Espaço', async () => {
    wrapper = await mountSuspended(WorkQueueListItem, {
      props: { item, selected: false },
      global: {
        stubs: {
          UBadge: true,
          UChip: true
        }
      }
    })

    const option = wrapper.get('[data-testid="work-queue-item"]')
    await option.trigger('keydown.enter')
    await option.trigger('keydown.space')

    expect(wrapper.emitted('select')).toEqual([[42], [42]])
  })

  it('restaura foco e visibilidade pelo id estável da linha', async () => {
    wrapper = await mountSuspended(WorkQueueListItem, {
      attachTo: document.body,
      props: { item, selected: true },
      global: {
        stubs: {
          UBadge: true,
          UChip: true
        }
      }
    })

    const option = wrapper.get('[data-testid="work-queue-item"]').element as HTMLElement
    option.scrollIntoView = vi.fn()

    const focused = (wrapper.vm as unknown as { focus: () => boolean }).focus()

    expect(focused).toBe(true)
    expect(document.activeElement).toBe(option)
    expect(option.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' })
  })

  it('restaura o elemento originador conectado em Lista/Kanban sem usar fallback', async () => {
    const origin = document.createElement('button')
    origin.scrollIntoView = vi.fn()
    document.body.append(origin)
    const fallback = vi.fn()

    await expect(restoreWorkSelectionFocus(origin, fallback)).resolves.toBe(true)

    expect(document.activeElement).toBe(origin)
    expect(origin.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' })
    expect(fallback).not.toHaveBeenCalled()
  })

  it('usa fallback estável quando o originador não está mais conectado', async () => {
    const origin = document.createElement('button')
    const fallback = vi.fn()

    await expect(restoreWorkSelectionFocus(origin, fallback)).resolves.toBe(false)

    expect(fallback).toHaveBeenCalledOnce()
  })
})
