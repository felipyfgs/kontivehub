import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import type { DropdownMenuItem } from '@nuxt/ui'
import ConversationActions from '../../app/components/communication/ConversationActions.vue'

let wrapper: VueWrapper | null = null

const DropdownStub = defineComponent({
  name: 'UDropdownMenuStub',
  props: {
    items: { type: Array, default: () => [] }
  },
  setup(_, { slots }) {
    return () => h('div', { 'data-testid': 'dropdown-stub' }, slots.default?.())
  }
})

const ButtonStub = defineComponent({
  inheritAttrs: false,
  setup(_, { attrs }) {
    return () => h('button', { ...attrs, type: 'button' })
  }
})

const PassThroughStub = defineComponent({
  inheritAttrs: false,
  setup(_, { attrs, slots }) {
    return () => h('div', attrs, slots.default?.())
  }
})

const conversation = {
  id: 12,
  inbox_id: 7,
  status: 'OPEN' as const,
  work_department_id: 3,
  assignee_membership_id: 4,
  priority: 0,
  lock_version: 9,
  unread_count: 2,
  display_name: 'Maria',
  labels: [{ id: 9, name: 'Urgente', color: '#ef4444' }]
}

const baseProps = {
  conversation,
  inbox: {
    id: 7,
    name: 'Atendimento',
    status: 'CONNECTED' as const,
    is_enabled: true,
    is_default: true,
    lock_version: 1,
    members: [
      { id: 4, name: 'Ana' },
      { id: 5, name: 'Bia' }
    ]
  },
  departments: [
    { id: 3, name: 'Fiscal', code: 'fiscal', is_active: true },
    { id: 8, name: 'Pessoal', code: 'pessoal', is_active: true }
  ],
  labels: [
    { id: 9, name: 'Urgente', color: '#ef4444' },
    { id: 10, name: 'Retorno', color: '#3b82f6' }
  ],
  canView: true,
  canReply: true
}

async function mountActions(props: Partial<typeof baseProps> = {}) {
  wrapper = await mountSuspended(ConversationActions, {
    props: { ...baseProps, ...props },
    global: {
      stubs: {
        UButton: ButtonStub,
        UDropdownMenu: DropdownStub,
        UTooltip: PassThroughStub
      }
    }
  })
  return wrapper
}

function allItems(view: VueWrapper): DropdownMenuItem[] {
  const groups = view.getComponent(DropdownStub).props('items') as DropdownMenuItem[][]
  return groups.flat()
}

function findItem(items: DropdownMenuItem[], label: string): DropdownMenuItem {
  function visit(candidates: DropdownMenuItem[]): DropdownMenuItem | undefined {
    for (const item of candidates) {
      if (item.label === label) return item
      if (item.children) {
        const found = visit(item.children.flat(Infinity) as DropdownMenuItem[])
        if (found) return found
      }
    }
    return undefined
  }
  const found = visit(items)
  if (found) return found
  throw new Error(`Item não encontrado: ${label}`)
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('ConversationActions', () => {
  it('compartilha apenas ações elegíveis e identifica estados atuais', async () => {
    const view = await mountActions()
    const items = allItems(view)

    expect(() => findItem(items, 'Abrir conversa')).toThrow()
    expect(() => findItem(items, 'Reabrir')).toThrow()
    expect(findItem(items, 'Ana').disabled).toBe(true)
    expect(findItem(items, 'Fiscal').disabled).toBe(true)
    expect(findItem(items, 'Urgente')).toMatchObject({ type: 'checkbox', checked: true })
    expect(findItem(items, 'Retorno')).toMatchObject({ type: 'checkbox', checked: false })

    findItem(items, 'Marcar como lida').onSelect?.(new Event('select'))
    await view.vm.$nextTick()
    expect(view.emitted('action')?.at(-1)).toEqual([{
      conversation,
      action: { type: 'MARK_READ' }
    }])

    const selectEvent = new Event('select', { cancelable: true })
    findItem(items, 'Retorno').onSelect?.(selectEvent)
    await view.vm.$nextTick()
    expect(selectEvent.defaultPrevented).toBe(true)
    expect(view.emitted('action')?.at(-1)).toEqual([{
      conversation,
      action: { type: 'SET_LABEL', label_id: 10, assigned: true }
    }])
  })

  it('não renderiza gatilho sem qualquer ação autorizada', async () => {
    const view = await mountActions({ canView: false, canReply: false })
    expect(view.find('[data-testid="communication-conversation-actions"]').exists()).toBe(false)
  })
})
