<script setup lang="ts">
/**
 * Widget de documentos cadastrais: Cartão CNPJ | Quadro de Sócios.
 * Sem download de PDF inventado — navega para seções do cadastro / docs.
 */
import { clientDetailHref } from '~/utils/client-detail-tabs'

const props = defineProps<{
  clientId: number
  shareholdersCount: number
}>()

const activeTab = ref<'cnpj' | 'qsa'>('cnpj')

const tabItems = [
  { label: 'Cartão CNPJ', value: 'cnpj' },
  { label: 'Quadro de Sócios', value: 'qsa' }
]

const cadastroHref = computed(() => clientDetailHref(props.clientId, 'cadastro'))
const docsHref = computed(() => `/docs?client_id=${props.clientId}`)
</script>

<template>
  <UCard
    variant="subtle"
    :ui="{ body: 'space-y-3 p-4 sm:p-4' }"
    data-testid="client-documents-widget"
  >
    <UTabs
      v-model="activeTab"
      :items="tabItems"
      :content="false"
      size="xs"
      color="neutral"
      variant="pill"
    />

    <div
      v-if="activeTab === 'cnpj'"
      class="flex items-center justify-between gap-3 rounded-lg bg-default/50 p-3 ring ring-inset ring-default"
    >
      <div class="flex min-w-0 items-center gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-elevated">
          <UIcon
            name="i-lucide-building-2"
            class="size-4 text-muted"
          />
        </div>
        <div class="min-w-0">
          <p class="text-sm font-medium text-highlighted">
            Cartão CNPJ
          </p>
          <p class="text-xs text-muted">
            Dados cadastrais RFB
          </p>
        </div>
      </div>
      <div class="flex shrink-0 gap-1">
        <UButton
          :to="cadastroHref"
          color="neutral"
          variant="ghost"
          icon="i-lucide-eye"
          square
          size="sm"
          aria-label="Ver cadastro"
        />
        <UButton
          :to="docsHref"
          color="neutral"
          variant="ghost"
          icon="i-lucide-folder-open"
          square
          size="sm"
          aria-label="Abrir documentos"
        />
      </div>
    </div>

    <div
      v-else
      class="flex items-center justify-between gap-3 rounded-lg bg-default/50 p-3 ring ring-inset ring-default"
    >
      <div class="flex min-w-0 items-center gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-elevated">
          <UIcon
            name="i-lucide-users"
            class="size-4 text-muted"
          />
        </div>
        <div class="min-w-0">
          <p class="text-sm font-medium text-highlighted">
            Quadro de Sócios
          </p>
          <p class="text-xs text-muted">
            {{ shareholdersCount > 0 ? `${shareholdersCount} sócio(s)` : 'Sem QSA carregado' }}
          </p>
        </div>
      </div>
      <UButton
        :to="cadastroHref"
        color="neutral"
        variant="ghost"
        icon="i-lucide-eye"
        square
        size="sm"
        aria-label="Ver quadro societário"
      />
    </div>
  </UCard>
</template>
