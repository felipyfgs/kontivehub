<script setup lang="ts">
/**
 * Shell do console global SERPRO (PLATFORM_ADMIN).
 * Fonte: `.local/references/dashboard/app/pages/settings.vue`.
 * Sidebar: um destino Admin → SERPRO. Operação / Integração / Canário ficam
 * na toolbar (SectionNavigation + SERPRO_NAV_ITEMS).
 */
import SectionNavigation from '~/components/navigation/SectionNavigation.vue'
import { SERPRO_NAV_ITEMS } from '~/utils/serpro-navigation'

const { canAccessPlatformSerpro } = useDashboard()
</script>

<template>
  <ShellSettingsShell
    id="admin-serpro"
    title="Integração SERPRO"
    test-id="admin-serpro-panel"
  >
    <template
      v-if="canAccessPlatformSerpro"
      #toolbar
    >
      <SectionNavigation
        :items="SERPRO_NAV_ITEMS"
        aria-label="Navegação do console SERPRO"
        test-id="admin-serpro-section-navigation"
      />
    </template>

    <UAlert
      v-if="!canAccessPlatformSerpro"
      color="warning"
      icon="i-lucide-shield-off"
      title="Acesso restrito à plataforma"
      data-testid="admin-serpro-denied"
    />
    <NuxtPage v-else />
  </ShellSettingsShell>
</template>
