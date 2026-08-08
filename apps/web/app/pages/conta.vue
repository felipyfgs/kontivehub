<script setup lang="ts">
/**
 * Shell da Conta — arquétipo settings do Nuxt UI Dashboard.
 * Fonte: `.local/reference/nuxt-dashboard-template/app/pages/settings.vue`
 * Uma seção = um path (sem subtabs).
 */
import { accountNavigationTree } from '~/utils/account-navigation'

const { me } = useDashboard()

const links = computed(() => accountNavigationTree(me.value))
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
      <NavigationSectionNavigation
        :items="links"
        test-id="account-section-navigation"
        aria-label="Navegação da conta"
      />
    </template>

    <NuxtPage />
  </ShellSettingsShell>
</template>
