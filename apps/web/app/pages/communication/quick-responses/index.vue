<script setup lang="ts">
/**
 * Gestão de respostas rápidas — composição da lista de gestão.
 * Arquétipo: customers.vue + cascas Shell produtivas.
 */
import { canViewCommunication } from '~/utils/permissions'

const { me, sessionEpoch } = useDashboard()
const canView = computed(() => canViewCommunication(me.value))

if (!canView.value) {
  await navigateTo('/')
}

const catalog = useCommunicationQuickResponsesCatalog()
</script>

<template>
  <ShellPagePanel
    id="communication-quick-responses"
    data-testid="communication-quick-responses-panel"
  >
    <template #header>
      <ShellPageNavbar title="Respostas rápidas">
        <template #right>
          <UButton
            v-if="catalog.canManage.value"
            icon="i-lucide-plus"
            label="Nova resposta"
            data-testid="communication-quick-responses-create"
            @click="catalog.openCreate"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar data-testid="communication-quick-responses-toolbar">
        <ShellListFilterToolbar
          :q="catalog.q.value"
          search-placeholder="Buscar por título ou atalho"
          search-aria-label="Buscar respostas rápidas"
          :definitions="catalog.filterDefinitions.value"
          :models="catalog.chipModels.value"
          :loading="catalog.loading.value"
          :reset-key="sessionEpoch"
          test-id-prefix="communication-quick-responses"
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
        Respostas rápidas de comunicação
      </h1>

      <UAlert
        v-if="!catalog.canManage.value"
        class="mb-4"
        color="neutral"
        variant="subtle"
        icon="i-lucide-eye"
        title="Modo leitura"
        description="Você pode consultar respostas ativas. Criar, editar, duplicar ou desativar exige a permissão de gestão."
      />

      <CommunicationQuickResponsesCatalogTable :catalog="catalog" />
      <CommunicationQuickResponsesEditorModal :catalog="catalog" />
      <CommunicationQuickResponsesDuplicateModal :catalog="catalog" />
      <CommunicationQuickResponsesDeactivateModal :catalog="catalog" />
    </template>
  </ShellPagePanel>
</template>
