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
        if (target.closest([
          '[data-testid="communication-filter-status-options"]',
          '[data-testid="communication-filter-advanced-trigger"]'
        ].join(','))) {
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

const TabsStub = defineComponent({
  inheritAttrs: false,
  props: {
    items: { type: Array, default: () => [] },
    modelValue: { type: [String, Number], default: '' }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit, slots }) {
    return () => h('div', { ...attrs, role: 'tablist' }, (
      props.items as Array<{
        label?: string
        value?: string | number
        testId?: string
        ariaLabel?: string
      }>
    ).map(item => h('button', {
      'type': 'button',
      'role': 'tab',
      'aria-selected': props.modelValue === item.value ? 'true' : 'false',
      'onClick': () => emit('update:modelValue', item.value)
    }, slots.default?.({ item }) ?? item.label)))
  }
})

const PassThroughStub = defineComponent({
  inheritAttrs: false,
  setup(_, { attrs, slots }) {
    return () => h('div', attrs, slots.default?.())
  }
})

const BadgeStub = defineComponent({
  inheritAttrs: false,
  props: {
    label: { type: [String, Number], default: '' }
  },
  setup(props, { attrs, slots }) {
    return () => h('span', attrs, slots.default?.() ?? String(props.label))
  }
})

const ChipStub = defineComponent({
  inheritAttrs: false,
  props: {
    show: { type: Boolean, default: true },
    text: { type: [String, Number], default: '' }
  },
  setup(props, { attrs, slots }) {
    return () => h('span', attrs, [
      slots.default?.(),
      props.show ? h('span', String(props.text)) : null
    ])
  }
})

const FormFieldStub = defineComponent({
  inheritAttrs: false,
  props: {
    label: { type: String, default: '' }
  },
  setup(props, { attrs, slots }) {
    return () => h('label', attrs, [
      h('span', props.label),
      slots.default?.()
    ])
  }
})

const baseProps = {
  selectionActive: false,
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
    attachTo: document.body,
    props: { ...baseProps, ...props },
    slots: {
      selection: '<div data-testid="communication-selection-slot">Seleção contextual</div>'
    },
    global: {
      stubs: {
        UBadge: BadgeStub,
        UButton: ButtonStub,
        UChip: ChipStub,
        UDropdownMenu: DropdownMenuStub,
        UFormField: FormFieldStub,
        UIcon: PassThroughStub,
        UInput: InputStub,
        UPopover: PopoverStub,
        USelect: SelectStub,
        USelectMenu: SelectStub,
        UTabs: TabsStub,
        UTooltip: PassThroughStub
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

async function openAdvancedEditor(view: VueWrapper): Promise<void> {
  if (!view.find('[data-testid="communication-filter-advanced-panel"]').exists()) {
    await view.get('[data-testid="communication-filter-advanced-trigger"]').trigger('click')
  }
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('ConversationListFilters — hierarquia compacta', () => {
  it('expõe foco acessível para a busca contextual', async () => {
    const view = await mountFilters()
    const focused = await (view.vm as unknown as {
      focusSearch: () => Promise<boolean>
    }).focusSearch()

    expect(focused).toBe(true)
    expect(document.activeElement?.getAttribute('data-testid')).toBe('communication-search')
  })

  it('mantém busca, três tabs pill fixas e status/ordenação no primeiro popover', async () => {
    const view = await mountFilters()

    expect(view.get('[data-testid="communication-search-row"]').exists()).toBe(true)
    expect(view.get('[role="tablist"]').attributes('variant')).toBe('pill')
    expect(view.get('[role="tablist"]').attributes('size')).toBe('xs')
    const tabs = view.findAll('[role="tab"]')
    expect(tabs[0]?.attributes('aria-selected')).toBe('true')
    expect(tabs[1]?.attributes('aria-selected')).toBe('false')
    expect(tabs.map(tab => tab.find('.sr-only').exists()
      ? tab.find('.sr-only').text()
      : tab.text())).toEqual([
      'Em aberto',
      'Não lidas',
      'Não atribuídas'
    ])

    await view.get('[data-testid="communication-filter-view-unread"]').trigger('click')
    expect(view.emitted('apply-quick-view')?.at(-1)).toEqual(['UNREAD'])

    await view.get('[data-testid="communication-filter-status-options"]').trigger('click')
    await view.get('[data-testid="communication-filter-status"]').setValue('PENDING')
    expect(view.emitted('update:status')?.at(-1)).toEqual(['PENDING'])
    await view.get('[data-testid="communication-filter-sort"]').setValue('unread_desc')
    expect(view.emitted('update:sortBy')?.at(-1)).toEqual(['unread_desc'])
  })

  it('mantém a identidade e a ordem das tabs sem marcar um preset falso', async () => {
    const view = await mountFilters({ status: 'PENDING', unreadOnly: true })

    expect(view.findAll('[role="tab"]').map(tab => tab.get('[data-testid]').attributes('data-testid'))).toEqual([
      'communication-filter-view-open',
      'communication-filter-view-unread',
      'communication-filter-view-unassigned'
    ])
    expect(view.findAll('[role="tab"][aria-selected="true"]')).toHaveLength(0)
    expect(view.get('[data-testid="communication-filter-status-options"]')
      .attributes('variant')).toBe('soft')
    expect(view.get('[data-testid="communication-filter-status-options"]')
      .attributes('aria-label')).toContain('filtro de status ativo')
  })

  it('não contabiliza no badge a condição já expressa pela visão rápida', async () => {
    const view = await mountFilters({ status: 'OPEN', unreadOnly: true })

    expect(view.findAll('[role="tab"]')[1]?.attributes('aria-selected')).toBe('true')
    expect(view.get('[data-testid="communication-filter-advanced-trigger"]')
      .attributes('aria-label')).toBe('Filtros avançados')
    expect(view.findComponent(ChipStub).props('show')).toBe(false)
    expect(view.findComponent(ChipStub).props('text')).toBe(0)
    expect(view.find('[data-testid="communication-filter-active-summary"]').exists()).toBe(false)
  })

  it('renova Não lidas por clique, Enter e Espaço quando a tab já está ativa', async () => {
    const view = await mountFilters({ status: 'OPEN', unreadOnly: true })
    const unreadTab = view.findAll('[role="tab"]')[1]
    if (!unreadTab) throw new Error('A tab Não lidas não foi renderizada.')

    await unreadTab.trigger('click')
    await unreadTab.trigger('keydown', { key: 'Enter' })
    await unreadTab.trigger('keydown', { key: ' ' })

    expect(view.emitted('refresh-unread-snapshot')).toHaveLength(3)
    expect(view.emitted('apply-quick-view')).toBeUndefined()
  })

  it('contabiliza somente o filtro avançado adicional à visão rápida', async () => {
    const view = await mountFilters({ status: 'OPEN', unreadOnly: true, inboxId: 7 })

    expect(view.findAll('[role="tab"]')[1]?.attributes('aria-selected')).toBe('true')
    expect(view.get('[data-testid="communication-filter-advanced-trigger"]')
      .attributes('aria-label')).toBe('Filtros avançados: 1 ativo')
    expect(view.findComponent(ChipStub).props('show')).toBe(true)
    expect(view.findComponent(ChipStub).props('text')).toBe(1)
    expect(view.findAll('[data-testid="communication-filter-active-chip"]')).toHaveLength(1)
  })

  it('substitui tabs e resumo pela seleção sem retirar a busca', async () => {
    const view = await mountFilters({
      selectionActive: true,
      contactFilterLabel: 'Maria'
    })

    expect(view.find('[data-testid="communication-search"]').exists()).toBe(true)
    expect(view.find('[data-testid="communication-filter-views"]').exists()).toBe(false)
    expect(view.find('[data-testid="communication-filter-active-summary"]').exists()).toBe(false)
    expect(view.get('[data-testid="communication-selection-slot"]').text())
      .toBe('Seleção contextual')
  })

  it('mantém mudanças no rascunho até aplicar e descarta ao fechar o popover', async () => {
    const view = await mountFilters()

    await openAdvancedEditor(view)
    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')

    expect(view.emitted('update:inboxId')).toBeUndefined()

    view.findAllComponents(PopoverStub)[1]?.vm.$emit('update:open', false)
    await view.vm.$nextTick()
    expect(view.find('[data-testid="communication-filter-advanced-panel"]').exists()).toBe(false)

    await openAdvancedEditor(view)
    expect(view.getComponent('[data-testid="communication-filter-inbox"]')
      .props('modelValue')).toBe('')

    await view.get('[data-testid="communication-filter-inbox"]').setValue('7')
    await view.get('[data-testid="communication-filter-advanced-apply"]').trigger('click')

    expect(view.emitted('update:inboxId')?.at(-1)).toEqual([7])
  })

  it('ressincroniza o rascunho aberto quando o escopo aplicado muda', async () => {
    const view = await mountFilters()

    await openAdvancedEditor(view)
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

    await openAdvancedEditor(view)
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

    await openAdvancedEditor(view)
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

    await openAdvancedEditor(view)
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

    await openAdvancedEditor(view)
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
    expect(view.get('[data-testid="communication-filter-active-more"]').text()).toBe('+1')
    expect(view.get('[data-testid="communication-filter-active-summary"]').attributes('role'))
      .toBe('group')
    expect(view.find('[data-testid="communication-filter-advanced-panel"]').exists()).toBe(false)
  })

  it('remove contato junto aos demais filtros em uma única confirmação', async () => {
    const view = await mountFilters({ contactFilterLabel: 'Maria' })

    await openAdvancedEditor(view)
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
