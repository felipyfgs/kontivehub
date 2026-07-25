<script setup lang="ts">
const { sessionEpoch } = useDashboard()
const catalog = useCommunicationContactsCatalog()
</script>

<template>
  <ShellPagePanel
    id="communication-contacts"
    data-testid="communication-contacts-panel"
  >
    <template #header>
      <ShellPageNavbar title="Contatos">
        <template #right>
          <UButton
            v-if="catalog.canManage.value"
            icon="i-lucide-plus"
            label="Novo contato"
            data-testid="communication-contacts-create"
            @click="() => { catalog.createOpen.value = true }"
          />
        </template>
      </ShellPageNavbar>

      <CommunicationContactsCatalogToolbar
        :q="catalog.q.value"
        :definitions="catalog.filterDefinitions"
        :models="catalog.chipModels.value"
        :loading="catalog.loading.value"
        :reset-key="sessionEpoch"
        @update:q="catalog.onSearch"
        @update:models="catalog.onStructuredFilters"
        @clear="catalog.clearFilters"
        @refresh="catalog.load"
      />
    </template>

    <template #body>
      <h1 data-testid="page-title" class="sr-only">
        Contatos de comunicação
      </h1>

      <CommunicationContactsCatalogTable
        :items="catalog.items.value"
        :loading="catalog.loading.value"
        :stale="catalog.stale.value"
        :error="catalog.loadError.value"
        :empty-kind="catalog.emptyKind.value"
        :page="catalog.page.value"
        :total="catalog.total.value"
        :per-page="catalog.perPage.value"
        :sorting="catalog.sortingState.value"
        :can-manage="catalog.canManage.value"
        @update:page="catalog.page.value = $event"
        @update:per-page="catalog.setPerPage"
        @update:sorting="catalog.onSortingUpdate"
        @open="catalog.openContact"
        @retry="catalog.load"
        @clear="catalog.clearFilters"
        @create="catalog.createOpen.value = true"
      />

      <CommunicationContactsCreateModal
        v-model:open="catalog.createOpen.value"
        :loading="catalog.creating.value"
        :error="catalog.createError.value"
        :can-manage="catalog.canManage.value"
        @submit="catalog.createContact"
      />
    </template>
  </ShellPagePanel>
</template>
