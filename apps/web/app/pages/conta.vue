<script setup lang="ts">
/**
 * Shell da Conta — arquétipo settings do Nuxt UI Dashboard.
 * Fonte: `.local/reference/nuxt-dashboard-template/app/pages/settings.vue`
 * Uma seção = um path (sem subtabs).
 */
import { accountNavigationMenu } from '~/utils/account-navigation'

const { me } = useDashboard()

const links = computed(() => accountNavigationMenu(me.value))
</script>

<template>
  <ShellSettingsShell
    id="account"
    title="Conta"
    width="comfortable"
    test-id="account-panel"
    toolbar-test-id="account-section-tabs"
  >
    <template
      v-if="links.length"
      #toolbar
    >
      <UNavigationMenu
        :items="links"
        highlight
        class="-mx-1 flex-1"
        data-testid="account-section-navigation"
        aria-label="Navegação da conta"
      />
    </template>

    <NuxtPage />
  </ShellSettingsShell>
</template>
