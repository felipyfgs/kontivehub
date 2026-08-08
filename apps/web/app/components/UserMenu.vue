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
const { logout } = useSanctumAuth()
const { me, canAccessPlatformAdmin } = useDashboard()

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
  if (me.value?.platform_role === 'platform_admin') return 'Proprietário da plataforma'
  if (me.value?.tenant_role === 'tenant_admin') return 'Administrador'
  if (me.value?.tenant_role === 'tenant_user') return me.value.permission_profile?.name || 'Usuário'
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
  </UDropdownMenu>
</template>
