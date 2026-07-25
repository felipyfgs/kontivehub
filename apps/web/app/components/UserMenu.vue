<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

/**
 * Rodapé da sidebar — estrutura de
 * `.local/reference/nuxt-dashboard-template/app/components/shell/UserMenu.vue`
 * (dropdown + botão block/ghost + chevrons + seletor de tema).
 */
defineProps<{
  collapsed?: boolean
}>()

const colorMode = useColorMode()
const appConfig = useAppConfig()
const { logout, refreshIdentity } = useSanctumAuth()
const { me, canAccessPlatformAdmin, openAssistantSlideover, assistantAvailable } = useDashboard()

const colors = ['red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose']
const neutrals = ['slate', 'gray', 'zinc', 'neutral', 'stone']

const displayUser = computed(() => ({
  name: me.value?.name || 'Usuário',
  avatar: {
    alt: me.value?.name || 'Usuário',
    text: (me.value?.name || 'U').slice(0, 1).toUpperCase()
  }
}))

async function onLogout() {
  await logout()
  await navigateTo('/login')
}

const { $pwa } = useNuxtApp()

const roleLabel = computed(() => {
  if (me.value?.is_platform_admin) return 'Proprietário da plataforma'
  const role = me.value?.role
  if (role === 'ADMIN') return 'Administrador'
  if (role === 'OPERATOR') return 'Operador'
  if (role === 'VIEWER') return 'Visualizador'
  return null
})

const items = computed<DropdownMenuItem[][]>(() => {
  const account: DropdownMenuItem[] = [{
    type: 'label',
    label: displayUser.value.name,
    avatar: displayUser.value.avatar,
    ...(roleLabel.value
      ? { description: roleLabel.value }
      : {})
  }]

  const profile: DropdownMenuItem[] = canAccessPlatformAdmin.value
    ? [{
        label: me.value?.email || 'Administração da plataforma',
        icon: 'i-lucide-shield',
        disabled: true
      }]
    : [{
        label: 'Conta',
        icon: 'i-lucide-user',
        to: '/conta',
        ...(roleLabel.value
          ? { description: `Papel: ${roleLabel.value}` }
          : {})
      }]

  const groups: DropdownMenuItem[][] = [account, profile]

  if (me.value && assistantAvailable.value) {
    groups.push([{
      label: 'Assistente',
      icon: 'i-lucide-sparkles',
      kbds: ['shift', 'A'],
      onSelect: () => {
        void refreshIdentity().catch(() => {}).finally(() => {
          openAssistantSlideover()
        })
      }
    }])
  }

  if ($pwa?.showInstallPrompt) {
    groups.push([{
      label: 'Instalar aplicativo',
      icon: 'i-lucide-download',
      onSelect: () => {
        void $pwa.install()
      }
    }])
  }

  groups.push([{
    label: 'Tema',
    icon: 'i-lucide-palette',
    children: [{
      label: 'Cor primária',
      slot: 'chip',
      chip: appConfig.ui.colors.primary,
      content: {
        align: 'center',
        collisionPadding: 16
      },
      children: colors.map(color => ({
        label: color,
        chip: color,
        slot: 'chip',
        checked: appConfig.ui.colors.primary === color,
        type: 'checkbox',
        onSelect: (e) => {
          e.preventDefault()

          appConfig.ui.colors.primary = color
        }
      }))
    }, {
      label: 'Cor neutra',
      slot: 'chip',
      chip: appConfig.ui.colors.neutral === 'neutral' ? 'old-neutral' : appConfig.ui.colors.neutral,
      content: {
        align: 'end',
        collisionPadding: 16
      },
      children: neutrals.map(color => ({
        label: color,
        chip: color === 'neutral' ? 'old-neutral' : color,
        slot: 'chip',
        type: 'checkbox',
        checked: appConfig.ui.colors.neutral === color,
        onSelect: (e) => {
          e.preventDefault()

          appConfig.ui.colors.neutral = color
        }
      }))
    }]
  }, {
    label: 'Aparência',
    icon: 'i-lucide-sun-moon',
    children: [{
      label: 'Claro',
      icon: 'i-lucide-sun',
      type: 'checkbox',
      checked: colorMode.value === 'light',
      onSelect(e: Event) {
        e.preventDefault()
        colorMode.preference = 'light'
      }
    }, {
      label: 'Escuro',
      icon: 'i-lucide-moon',
      type: 'checkbox',
      checked: colorMode.value === 'dark',
      onSelect(e: Event) {
        e.preventDefault()
        colorMode.preference = 'dark'
      }
    }]
  }], [{
    label: 'Sair',
    icon: 'i-lucide-log-out',
    color: 'error',
    onSelect: () => {
      onLogout()
    }
  }])

  return groups
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
  >
    <UButton
      v-bind="{
        ...displayUser,
        label: collapsed ? undefined : displayUser.name,
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down'
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :class="[!collapsed && 'py-2']"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
      :aria-label="`Menu do usuário: ${displayUser.name}`"
      data-testid="user-menu"
    />

    <template #chip-leading="{ item }">
      <div class="inline-flex items-center justify-center shrink-0 size-5">
        <span
          class="rounded-full ring ring-bg bg-(--chip-light) dark:bg-(--chip-dark) size-2"
          :style="{
            '--chip-light': `var(--color-${(item as any).chip}-500)`,
            '--chip-dark': `var(--color-${(item as any).chip}-400)`
          }"
        />
      </div>
    </template>
  </UDropdownMenu>
</template>
