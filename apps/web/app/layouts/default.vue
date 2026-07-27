<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import {
  mainDestinations,
  quickActions,
  searchableDestinations,
  secondaryDestinations,
  sidebarDestinationGroups,
  toNavigationItems
} from '~/utils/navigation'

/**
 * Shell autenticado — copiado de
 * `.local/reference/nuxt-dashboard-template/app/layouts/default.vue`
 *
 * UNavigationMenu vertical (docs Nuxt UI):
 * - estado aberto via `defaultOpen` nos items (Accordion interno)
 * - SEM v-model / modelValue / remount / handlers de “nudge”
 * - type padrão = multiple (como o template e o default do componente)
 *
 * Só adaptamos: TenantIdentity, destinos/permissões, command palette.
 * Slot direto no UDashboardGroup (sem wrapper) — obrigatório para
 * master–detalhe (work, communication, mailbox) com UDashboardPanel irmãos.
 *
 * @see .local/reference/nuxt-dashboard-template/app/layouts/default.vue
 * @see https://ui.nuxt.com/docs/components/navigation-menu#orientation
 * @see https://ui.nuxt.com/docs/components/dashboard-sidebar
 * @see https://ui.nuxt.com/docs/components/dashboard-group
 */
const route = useRoute()
const open = ref(false)
const { me, openClientCreate, openExportCreate } = useDashboard()

const closeSidebar = () => {
  open.value = false
}

/**
 * Items estáveis: só reconstroem quando path ou papéis mudam.
 * Recriar o array a cada tick de `me` faz o Accordion “piscar”.
 */
const primaryItems = shallowRef<NavigationMenuItem[][]>([])
const secondaryItems = shallowRef<NavigationMenuItem[]>([])

function rebuildNav() {
  primaryItems.value = sidebarDestinationGroups(
    mainDestinations(me.value, { path: route.path })
  ).map(group => toNavigationItems(group, closeSidebar))
  secondaryItems.value = toNavigationItems(
    secondaryDestinations(),
    closeSidebar
  )
}

watch(
  () => [
    route.path,
    me.value?.id,
    me.value?.tenant_role,
    me.value?.access_mode,
    me.value?.platform_role,
    me.value?.has_real_membership,
    me.value?.real_tenant_role,
    // lacksTenantContext / quickActions dependem destes campos
    me.value?.context_status,
    me.value?.current_tenant?.id ?? null
  ] as const,
  () => {
    rebuildNav()
  },
  { immediate: true }
)

const groups = computed(() => {
  const destinations = [
    ...searchableDestinations(me.value, {
      path: route.path
    }),
    ...secondaryDestinations()
  ]

  return [{
    id: 'links',
    label: 'Ir para',
    items: destinations
      .filter(item => item.to)
      .map(item => ({
        id: item.id,
        label: item.label,
        icon: item.icon,
        to: item.to,
        target: item.target
      }))
  }, {
    id: 'actions',
    label: 'Ações',
    items: quickActions(me.value).map(action => ({
      id: action.id,
      label: action.label,
      icon: action.icon,
      to: action.to,
      onSelect: action.id === 'new-client'
        ? openClientCreate
        : action.id === 'new-export'
          ? openExportCreate
          : undefined
    }))
  }]
})
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      data-testid="shell-sidebar"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <TenantIdentity :collapsed="collapsed" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton
          :collapsed="collapsed"
          class="bg-transparent ring-default"
        />

        <!-- Igual ao template: sem type/v-model/key/default-value externos -->
        <UNavigationMenu
          :collapsed="collapsed"
          :items="primaryItems"
          orientation="vertical"
          tooltip
          popover
          data-testid="shell-sidebar-primary"
        />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="secondaryItems"
          orientation="vertical"
          tooltip
          class="mt-auto"
          data-testid="shell-sidebar-secondary"
        />
      </template>

      <template #footer="{ collapsed }">
        <div class="flex w-full flex-col gap-1">
          <AssistantTriggerButton :collapsed="collapsed" />
          <UserMenu :collapsed="collapsed" />
        </div>
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="groups" />

    <slot />

    <NotificationsSlideover />
    <AssistantSlideover />
  </UDashboardGroup>
</template>
