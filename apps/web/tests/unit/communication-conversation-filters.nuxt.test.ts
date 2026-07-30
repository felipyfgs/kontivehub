import { mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, describe, expect, it } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import ConversationListFilters from '../../app/components/communication/ConversationListFilters.vue'

let wrapper: VueWrapper | null = null

const ButtonStub = defineComponent({
  inheritAttrs: false,
  props: {
    label: { type: String, default: '' }
  },
  emits: ['click'],
  setup(props, { attrs, emit, slots }) {
    return () => h('button', {
      ...attrs,
      type: 'button',
      onClick: (event: MouseEvent) => emit('click', event)
    }, [props.label, slots.default?.(), slots.trailing?.()])
  }
})

const InputStub = defineComponent({
  inheritAttrs: false,
  props: {
    modelValue: { type: String, default: '' }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('input', {
      ...attrs,
      value: props.modelValue,
      onInput: (event: Event) => emit(
        'update:modelValue',
        (event.target as HTMLInputElement).value
      )
    })
  }
})

const SelectStub = defineComponent({
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number, Array], default: '' },
    items: { type: Array, default: () => [] },
    multiple: { type: Boolean, default: false }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('select', {
      ...attrs,
      multiple: props.multiple,
      value: props.modelValue,
      onChange: (event: Event) => {
        const target = event.target as HTMLSelectElement
        if (props.multiple) {
          emit('update:modelValue', [...target.selectedOptions].map(option => Number(option.value)))
          return
        }
        const item = (props.items as Array<{ value?: string | number }>).find(
          candidate => String(candidate.value) === target.value
        )
        emit('update:modelValue', item?.value ?? target.value)
      }
    }, (props.items as Array<{ label?: string, value?: string | number }>).map(item => h(
      'option',
      { value: item.value },
      item.label
    )))
  }
})

const PopoverStub = defineComponent({
  name: 'UPopoverStub',
  props: {
    open: { type: Boolean, default: false }
  },
  emits: ['update:open'],
  setup(props, { emit, slots }) {
    const close = () => emit('update:open', false)
    return () => h('div', {
      onClick: (event: MouseEvent) => {
        const target = event.target
        if (!(target instanceof Element)) return
        if (target.closest('[data-testid="communication-filter-advanced-toggle"]')) {
          emit('update:open', !props.open)
        }
      },
      ...{ 'data-testid': 'popover-stub' }
    }, [
      slots.default?.({ open: props.open }),
      props.open ? slots.content?.({ close }) : null
    ])
  }
})

const DropdownMenuStub = defineComponent({
  inheritAttrs: false,
  props: {
    items: { type: Array, default: () => [] }
  },
  setup(props, { slots }) {
    return () => {
      const items = (props.items as Array<Array<Record<string, unknown>> | Record<string, unknown>>)
        .flatMap(item => Array.isArray(item) ? item : [item])
        .filter(item => item.type !== 'label')

      return h('div', [
        slots.default?.(),
        ...items.map(item => h('button', {
          type: 'button',
          onClick: () => (item.onSelect as ((event: Event) => void) | undefined)?.(
            new Event('select')
          ),
          ...{ 'data-testid': item['data-testid'] }
        }, String(item.label ?? '')))
      ])
    }
  }
})

const PassThroughStub = defineComponent({
  inheritAttrs: false,
  setup(_, { attrs, slots }) {
    return () => h('div', attrs, slots.default?.())
  }
})

const baseProps = {
  search: '',
  status: 'OPEN' as const,
  inboxId: 0,
  assigneeId: 0,
  departmentId: 0,
  labelIds: [] as number[],
  sortBy: 'last_activity_desc' as const,
  unassignedOnly: false,
  unreadOnly: false,
  contactFilterLabel: null,
  inboxItems: [
    { label: 'Todas as inboxes', value: 0 },
    { label: 'Atendimento', value: 7 }
  ],
  assigneeItems: [
    { label: 'Qualquer responsável', value: 0 },
    { label: 'Ana', value: 4 }
  ],
  departmentItems: [
    { label: 'Qualquer fila', value: 0 },
    { label: 'Fiscal', value: 3 }
  ],
  labelItems: [
    { label: 'Urgente', value: 9 },
    { label: 'Retorno', value: 10 }
  ]
}

async function mountFilters(props: Partial<typeof baseProps> = {}) {
  wrapper = await mountSuspended(ConversationListFilters, {
    props: { ...baseProps, ...props },
    global: {
      stubs: {
        UButton: ButtonStub,
        UDropdownMenu: DropdownMenuStub,
        UIcon: PassThroughStub,
        UInput: InputStub,
        UPopover: PopoverStub,
        USelect: SelectStub,
        USelectMenu: SelectStub
      }
    }
  })
  return wrapper
}

function firstAdvancedRuleId(view: VueWrapper): string {
  const rule = view.get('[role="listitem"][data-testid^="communication-filter-rule-"]')
  const testId = rule.attributes('data-testid')
  if (!testId) throw new Error('A primeira regra avançada não expôs data-testid.')
  return testId.replace('communication-filter-rule-', '')
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('ConversationListFilters — hierarquia compacta', () => {
  it('aplica status e ordenação por dropdowns iconográficos independentes', async () => {
    const view = await mountFilters()

    expect(view.get('[data-testid="communication-filter-status"]').text()).toBe('')
    expect(view.get('[data-testid="communication-filter-sort"]').text()).toBe('')
    expect(view.get('[data-testid="communication-filter-advanced-toggle"]').text()).toBe('')

    await view.get('[data-testid="communication-filter-status-option-PENDING"]').trigger('click')
    await view.get('[data-testid="communication-filter-sort-option-unread_desc"]').trigger('click')

    expect(view.emitted('update:status')?.at(-1)).toEqual(['PENDING'])
    expect(view.emitted('update:sortBy')?.at(-1)).toEqual(['unread_desc'])
  })

  it('mantém mudanças no rascunho até aplicar e descarta ao fechar o popover', async () => {
    const view = await mountFilters()

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')

    expect(view.emitted('update:inboxId')).toBeUndefined()

    view.getComponent(PopoverStub).vm.$emit('update:open', false)
    await view.vm.$nextTick()
    expect(view.find('[data-testid="communication-filter-advanced-panel"]').exists()).toBe(false)

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    expect(view.getComponent('[data-testid="communication-filter-inbox"]')
      .props('modelValue')).toBe('')

    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')
    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')

    expect(view.emitted('update:inboxId')?.at(-1)).toEqual([7])
  })

  it('ressincroniza o rascunho aberto quando o escopo aplicado muda', async () => {
    const view = await mountFilters()

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')
    await view.setProps({ inboxId: 7, assigneeId: 4 })
    await view.vm.$nextTick()

    expect(view.getComponent('[data-testid="communication-filter-inbox"]')
      .props('modelValue')).toBe(7)
    expect(view.getComponent('[data-testid="communication-filter-assignee"]')
      .props('modelValue')).toBe(4)
    expect(view.get('[data-testid="communication-filter-advanced-apply"]')
      .attributes('disabled')).toBeDefined()
  })

  it('preserva o rascunho e bloqueia aplicação de regra sem valor', async () => {
    const view = await mountFilters()

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    const firstRuleId = firstAdvancedRuleId(view)
    await view.get(`[data-testid="communication-filter-rule-field-${firstRuleId}"]`)
      .setValue('unread')
    await view.get('[data-testid="communication-filter-rule-add"]').trigger('click')

    const apply = view.get('[data-testid="communication-filter-advanced-apply"]')
    expect(apply.attributes('disabled')).toBeDefined()
    expect(view.get('[data-testid="communication-filter-advanced-incomplete"]').text())
      .toContain('Selecione um valor')
    await apply.trigger('click')
    expect(view.emitted('update:unreadOnly')).toBeUndefined()

    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')
    expect(view.find('[data-testid="communication-filter-advanced-incomplete"]').exists())
      .toBe(false)
    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')
    expect(view.emitted('update:unreadOnly')?.at(-1)).toEqual([true])
    expect(view.emitted('update:inboxId')?.at(-1)).toEqual([7])
  })

  it('expõe operadores compatíveis e combina regras avançadas com E', async () => {
    const view = await mountFilters()

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    const firstRuleId = firstAdvancedRuleId(view)

    expect(view.get(`[data-testid="communication-filter-rule-operator-${firstRuleId}"]`).text())
      .toBe('Igual a')

    await view.get(`[data-testid="communication-filter-rule-field-${firstRuleId}"]`)
      .setValue('labels')
    expect(view.get(`[data-testid="communication-filter-rule-operator-${firstRuleId}"]`).text())
      .toBe('Contém qualquer')

    await view.get('[data-testid="communication-filter-labels"]').setValue(['9'])
    await view.get('[data-testid="communication-filter-rule-add"]').trigger('click')
    expect(view.text()).toContain('E')
    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')

    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')
    expect(view.emitted('update:labelIds')?.at(-1)).toEqual([[9]])
    expect(view.emitted('update:inboxId')?.at(-1)).toEqual([7])
  })

  it('preserva exclusão mútua entre responsável e sem responsável', async () => {
    const view = await mountFilters()

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    const field = view.get('[data-testid^="communication-filter-rule-field-"]')
    await field.setValue('assignee')
    await view.get('[data-testid="communication-filter-assignee"]').setValue('4')
    await field.setValue('unassigned')
    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')

    expect(view.emitted('update:assigneeId')?.at(-1)).toEqual([0])
    expect(view.emitted('update:unassignedOnly')?.at(-1)).toEqual([true])
  })

  it('normaliza responsável incompatível com sem responsável no escopo aplicado', async () => {
    const view = await mountFilters({ assigneeId: 4, unassignedOnly: true })

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    expect(view.find('[data-testid="communication-filter-assignee"]').exists()).toBe(false)
    expect(view.get('[data-testid="communication-filter-unassigned"]').text()).toBe('Sim')

    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')
    expect(view.emitted('update:assigneeId')?.at(-1)).toEqual([0])
    expect(view.emitted('update:unassignedOnly')?.at(-1)).toEqual([true])
  })

  it('resume no máximo dois filtros ativos e mantém o popover fechado no deep-link', async () => {
    const view = await mountFilters({
      contactFilterLabel: 'Maria',
      unreadOnly: true,
      assigneeId: 4,
      inboxId: 7
    })

    expect(view.findAll('[data-testid="communication-filter-active-chip"]')).toHaveLength(2)
    expect(view.findAll('[data-testid="communication-filter-active-chip"]')[0]
      ?.attributes('aria-label')).toBe('Editar filtro: Contato: Maria')
    expect(view.get('[data-testid="communication-filter-active-more"]').text()).toBe('+2')
    expect(view.get('[data-testid="communication-filter-active-summary"]').attributes('role'))
      .toBe('group')
    expect(view.find('[data-testid="communication-filter-advanced-panel"]').exists()).toBe(false)
  })

  it('remove contato junto aos demais filtros em uma única confirmação', async () => {
    const view = await mountFilters({ contactFilterLabel: 'Maria' })

    await view.get('[data-testid="communication-filter-advanced-toggle"]').trigger('click')
    const contactRuleId = firstAdvancedRuleId(view)
    await view.get(`[data-testid="communication-filter-rule-remove-${contactRuleId}"]`)
      .trigger('click')
    await view.get('[data-testid="communication-filter-rule-add"]').trigger('click')
    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')
    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')

    expect(view.emitted('clear-contact')?.at(-1)).toEqual([])
    expect(view.emitted('update:inboxId')?.at(-1)).toEqual([7])
  })
})
