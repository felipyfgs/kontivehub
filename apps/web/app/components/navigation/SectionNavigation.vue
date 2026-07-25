<script setup lang="ts">
/**
 * Navegação contextual responsiva (arquétipo Settings).
 *
 * Desktop: Tabs → Subtabs (dois UNavigationMenu). Grupos são links (sem dropdown);
 * a 2ª faixa só aparece quando o grupo ativo tem 2+ folhas.
 * Mobile: um único USelectMenu com todas as folhas autorizadas.
 *
 * O catálogo recebido já deve estar filtrado por autorização/tenancy.
 */
import type { NavigationMenuItem } from '@nuxt/ui'
import type { NavLayerItem, NavLeafDestination } from '~/utils/navigation-hierarchy'
import {
  flattenNavLeaves,
  normalizeNavPath,
  resolveNavSelection
} from '~/utils/navigation-hierarchy'
import type { SectionNavLinkItem, SectionNavSelectOption } from '~/utils/section-navigation'
import {
  toDesktopSubtabItems,
  toDesktopTabItems,
  toMobileSelectOptions
} from '~/utils/section-navigation'

const props = withDefaults(defineProps<{
  items: NavLayerItem[]
  /** Localização atual, incluindo query quando ela diferencia seções. */
  path?: string
  ariaLabel?: string
  testId?: string
  /** Quando false, emite `navigate` em vez de chamar o router. */
  navigateWithRouter?: boolean
}>(), {
  ariaLabel: 'Navegação da seção',
  testId: 'section-navigation',
  navigateWithRouter: true
})

const emit = defineEmits<{
  navigate: [leaf: NavLeafDestination]
}>()

const route = useRoute()
const router = useRouter()
const currentLocation = computed(() => props.path ?? route.fullPath ?? route.path)
const selection = computed(() => resolveNavSelection(props.items, currentLocation.value))

const tabLinks = computed(() => toDesktopTabItems(selection.value, currentLocation.value))
const subtabLinks = computed(() => toDesktopSubtabItems(selection.value, currentLocation.value))
const mobileItems = computed(() => toMobileSelectOptions(props.items))

function toMenuItem(link: SectionNavLinkItem): NavigationMenuItem {
  const base: NavigationMenuItem = {
    label: link.label,
    icon: link.icon,
    active: link.active,
    exact: link.exact === true
  }
  if (props.navigateWithRouter) return { ...base, to: link.to }
  return { ...base, onSelect: () => emit('navigate', link.leaf) }
}

const desktopTabItems = computed<NavigationMenuItem[]>(() =>
  tabLinks.value.map(toMenuItem)
)
const desktopSubtabItems = computed<NavigationMenuItem[]>(() =>
  subtabLinks.value.map(toMenuItem)
)

const selectedLeafId = computed(() =>
  selection.value.leaf?.id ?? flattenNavLeaves(props.items)[0]?.id
)
const selectedOption = computed(() =>
  mobileItems.value.find(item => item.id === selectedLeafId.value)
)

function sameRouteDestination(to: string): boolean {
  if (normalizeNavPath(to) !== normalizeNavPath(currentLocation.value)) return false

  const [, targetQuery = ''] = to.split('?')
  if (!targetQuery) return !currentLocation.value.includes('?')

  const [, currentQuery = ''] = currentLocation.value.split('?')
  const target = new URLSearchParams(targetQuery)
  const current = new URLSearchParams(currentQuery)
  if (target.size !== current.size) return false

  return [...target.entries()].every(([key, value]) => current.get(key) === value)
}

async function navigateToOption(option: SectionNavSelectOption | undefined) {
  if (!option) return
  if (!props.navigateWithRouter) {
    emit('navigate', option.leaf)
    return
  }
  if (sameRouteDestination(option.leaf.to)) return
  await router.push(option.leaf.to)
}

function onMobileSelect(value: unknown) {
  const id = String(value ?? '')
  void navigateToOption(mobileItems.value.find(item => item.id === id))
}

const menuUi = {
  linkLabel: 'whitespace-nowrap',
  childLinkLabel: 'whitespace-nowrap'
}
</script>

<template>
  <div
    :data-testid="testId"
    class="min-w-0 flex-1"
  >
    <div
      class="hidden min-w-0 flex-col gap-1 lg:flex"
      data-testid="section-nav-desktop"
    >
      <UNavigationMenu
        :items="desktopTabItems"
        highlight
        class="-mx-1 flex-1"
        :aria-label="ariaLabel"
        :ui="menuUi"
      />
      <UNavigationMenu
        v-if="desktopSubtabItems.length"
        :items="desktopSubtabItems"
        highlight
        class="-mx-1 flex-1"
        data-testid="section-nav-subtabs"
        :aria-label="`${ariaLabel} · seções`"
        :ui="menuUi"
      />
    </div>

    <div
      class="lg:hidden"
      data-testid="section-nav-mobile"
    >
      <label
        class="sr-only"
        :for="`${testId}-select`"
      >{{ ariaLabel }}</label>
      <USelectMenu
        :id="`${testId}-select`"
        :model-value="selectedLeafId"
        :items="mobileItems"
        value-key="id"
        :search-input="false"
        color="neutral"
        variant="outline"
        class="w-full"
        :ui="{
          base: 'min-h-11 w-full justify-between',
          trailing: 'min-h-11'
        }"
        :aria-label="ariaLabel"
        @update:model-value="onMobileSelect"
      >
        <template #default>
          <span class="truncate text-left">{{ selectedOption?.label || 'Selecionar seção' }}</span>
        </template>
      </USelectMenu>
    </div>
  </div>
</template>
