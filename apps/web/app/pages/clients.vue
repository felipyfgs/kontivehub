<script setup lang="ts">
/**
 * Shell de catálogo de clientes — arquétipo Lista do template.
 * A navegação Lista/Dashboard pertence exclusivamente ao sidebar.
 *
 * Detalhe /clients/:id tem painel próprio e não usa este chrome.
 */
import {
  clientsCatalogChromeKey,
  createClientsCatalogChrome
} from '~/composables/useClientsCatalogChrome'

const route = useRoute()
const router = useRouter()
const { canManageClients, openClientCreate } = useDashboard()
const catalogChrome = createClientsCatalogChrome()
provide(clientsCatalogChromeKey, catalogChrome)
const dashboardLoading = catalogChrome.loading

/** Lista e Dashboard compartilham o shell; detalhe do cliente não. */
const isCatalog = computed(() => {
  const path = route.path.replace(/\/$/, '') || '/'
  return path === '/clients' || path === '/clients/dashboard'
})

/** Lista = customers.vue: conteúdo direto no #body (não via NuxtPage). */
const isList = computed(() => {
  const path = route.path.replace(/\/$/, '') || '/'
  return path === '/clients'
})

const isDashboard = computed(() => {
  const path = route.path.replace(/\/$/, '') || '/'
  return path === '/clients/dashboard'
})

/**
 * Compatibilidade: `?new=1` abre o modal uma vez e some da URL.
 * Demais query params (filtros da lista) são preservados.
 */
watch(
  () => route.fullPath,
  async () => {
    if (!isCatalog.value || route.path !== '/clients' || route.query.new !== '1') return
    const { new: _new, ...rest } = route.query
    await router.replace({ path: route.path, query: rest })
    await openClientCreate()
  },
  { immediate: true }
)
</script>

<template>
  <ShellPagePanel v-if="isCatalog" id="clients">
    <template #header>
      <ShellPageNavbar title="Clientes">
        <template #right>
          <ShellNavbarRefresh
            v-if="isDashboard"
            :loading="dashboardLoading"
            aria-label="Atualizar dashboard"
            test-id="clients-dashboard-refresh"
            @click="catalogChrome.reload"
          />
          <UButton
            v-if="canManageClients"
            icon="i-lucide-plus"
            label="Novo cliente"
            @click="openClientCreate"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <ClientsClientCatalogList v-if="isList" />
      <NuxtPage v-else />
    </template>
  </ShellPagePanel>

  <!-- /clients/:id… — painel próprio em [id].vue -->
  <NuxtPage v-else />
</template>
