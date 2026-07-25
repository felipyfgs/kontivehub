<script setup lang="ts">
/**
 * Shell do console global SERPRO (PLATFORM_ADMIN).
 * Sidebar: um destino Admin → SERPRO. Operação / Integração / Canário ficam
 * na toolbar (SectionNavigation + SERPRO_NAV_ITEMS).
 */
import SectionNavigation from '~/components/navigation/SectionNavigation.vue'
import { SERPRO_NAV_ITEMS } from '~/utils/serpro-navigation'

const { canAccessPlatformSerpro } = useDashboard()
</script>

<template>
  <UDashboardPanel
    id="admin-serpro"
    data-testid="admin-serpro-panel"
    :ui="{ body: 'lg:py-12' }"
  >
    <template #header>
      <UDashboardNavbar
        title="Integração SERPRO"
        data-testid="page-navbar"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>

      <UDashboardToolbar v-if="canAccessPlatformSerpro">
        <SectionNavigation
          :items="SERPRO_NAV_ITEMS"
          aria-label="Navegação do console SERPRO"
          test-id="admin-serpro-section-navigation"
        />
      </UDashboardToolbar>
    </template>

    <template #body>
      <DashboardContent width="comfortable" class="gap-4 sm:gap-6 lg:gap-12">
        <UAlert
          v-if="!canAccessPlatformSerpro"
          color="warning"
          icon="i-lucide-shield-off"
          title="Acesso restrito à plataforma"
          data-testid="admin-serpro-denied"
        />
        <NuxtPage v-else />
      </DashboardContent>
    </template>
  </UDashboardPanel>
</template>
