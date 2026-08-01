<script setup lang="ts">
/**
 * Gestão administrativa de fluxos de comunicação — lista (ShellDataTable).
 * Arquétipo: lista de gestão. Análogo: communication/quick-responses/index.vue.
 * Editor visual vive em /communication/flows/:id/editor.
 */
import { canViewCommunication } from '~/utils/permissions'

const { me, sessionEpoch } = useDashboard()

if (!canViewCommunication(me.value)) {
  await navigateTo('/')
}

const catalog = useCommunicationFlowsCatalog()
</script>

<template>
  <ShellPagePanel
    id="communication-flows"
    data-testid="communication-flows-panel"
  >
    <template #header>
      <ShellPageNavbar title="Fluxos">
        <template #right>
          <UButton
            v-if="catalog.canManage.value"
            icon="i-lucide-plus"
            label="Novo fluxo"
            data-testid="communication-flows-create"
            :disabled="Boolean(catalog.mutationBlocked.value)"
            @click="catalog.openCreate"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar data-testid="communication-flows-toolbar">
        <ShellListFilterToolbar
          :q="catalog.q.value"
          search-placeholder="Buscar por nome"
          search-aria-label="Buscar fluxos"
          :definitions="catalog.filterDefinitions.value"
          :models="catalog.chipModels.value"
          :loading="catalog.loading.value"
          :reset-key="sessionEpoch"
          test-id-prefix="communication-flows"
          @update:q="catalog.onSearch"
          @update:models="catalog.onStructuredFilters"
          @clear="catalog.clearFilters"
          @refresh="catalog.load"
        />
      </UDashboardToolbar>
    </template>

    <template #body>
      <h1
        data-testid="page-title"
        class="sr-only"
      >
        Fluxos de comunicação
      </h1>

      <UAlert
        v-if="!catalog.flowsEnabled.value"
        class="mb-4"
        color="warning"
        variant="subtle"
        icon="i-lucide-shield-off"
        title="Engine de fluxos desabilitada"
        description="A flag fail-closed está OFF. Você pode consultar fluxos, mas criar, editar, publicar ou vincular bindings permanece bloqueado."
        data-testid="communication-flows-disabled-alert"
      />

      <UAlert
        v-else-if="!catalog.canManage.value"
        class="mb-4"
        color="neutral"
        variant="subtle"
        icon="i-lucide-eye"
        title="Modo leitura"
        description="Você pode consultar fluxos. Mutações exigem a permissão communication.manage_flows."
      />

      <CommunicationFlowsCatalogTable :catalog="catalog" />
      <CommunicationFlowsCatalogModals :catalog="catalog" />
    </template>
  </ShellPagePanel>
</template>
