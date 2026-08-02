import { mountSuspended } from '@nuxt/test-utils/runtime'
import type { VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import CatalogToolbar from '../../app/components/communication/contacts/CatalogToolbar.vue'

let wrapper: VueWrapper | null = null

const SelectStub = defineComponent({
  inheritAttrs: false,
  props: {
    modelValue: { type: [String, Number], default: 0 },
    items: { type: Array, default: () => [] }
  },
  emits: ['update:modelValue'],
  setup(props, { attrs, emit }) {
    return () => h('select', {
      ...attrs,
      value: props.modelValue,
      onChange: (event: Event) => emit(
        'update:modelValue',
        Number((event.target as HTMLSelectElement).value)
      )
    }, (props.items as Array<{ label: string, value: number }>).map(item => h(
      'option',
      { value: item.value },
      item.label
    )))
  }
})

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
    }, [props.label, slots.default?.()])
  }
})

const PassThroughStub = defineComponent({
  setup(_, { slots }) {
    return () => h('div', slots.default?.())
  }
})

const FilterStub = defineComponent({
  emits: ['update:modelValue', 'clear'],
  setup(_, { attrs }) {
    return () => h('div', attrs)
  }
})

async function mountToolbar(error: string | null = null) {
  wrapper = await mountSuspended(CatalogToolbar, {
    props: {
      q: '',
      inboxId: null,
      inboxes: [
        {
          id: 2,
          name: 'Comercial',
          status: 'CONNECTED',
          is_enabled: true,
          is_default: true
        }
      ],
      inboxesLoading: false,
      inboxesError: error,
      definitions: [],
      models: [],
      loading: false,
      resetKey: 1,
      sort: 'name',
      sortDirection: 'asc',
      canManage: false
    },
    global: {
      stubs: {
        UInput: defineComponent({ setup: (_, { attrs }) => () => h('input', attrs) }),
        USelect: SelectStub,
        UButton: ButtonStub,
        UTooltip: PassThroughStub,
        UDropdownMenu: PassThroughStub,
        DataTableFilterRoot: FilterStub
      }
    }
  })
  return wrapper
}

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
})

describe('CommunicationContactsCatalogToolbar', () => {
  it('emite a inbox selecionada e restaura o catálogo geral', async () => {
    const mounted = await mountToolbar()
    const select = mounted.get('[data-testid="communication-contacts-inbox"]')

    await select.setValue('2')
    await select.setValue('0')

    expect(mounted.emitted('update:inboxId')).toEqual([[2], [null]])
    expect(select.attributes('aria-label')).toBe('Filtrar contatos por inbox')
  })

  it('expõe retry acessível sem bloquear os demais filtros', async () => {
    const mounted = await mountToolbar('Falha ao carregar inboxes.')

    await mounted.get('[data-testid="communication-contacts-inbox-retry"]').trigger('click')

    expect(mounted.emitted('retryInboxes')).toHaveLength(1)
    expect(mounted.get('[data-testid="communication-contacts-q"]').exists()).toBe(true)
    expect(mounted.get('[data-testid="communication-contacts-inbox"]').exists()).toBe(true)
  })
})
