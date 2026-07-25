<script setup lang="ts">
import type { DefisSpecificDeclarationHistoryPayload } from '~/types/fiscal-modules'
import { resolveApiUrl } from '~/utils/api-url'

const props = defineProps<{ open: boolean, clientId: number | null, clientName?: string | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean], 'consult': [referenceId: number] }>()
const { fetchHistory } = useDefisSpecificDeclarationMonitoring()
const apiBase = useRuntimeConfig().public.apiBase as string
const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<DefisSpecificDeclarationHistoryPayload | null>(null)
const selectedReferenceId = ref<number | null>(null)
let generation = 0

function typeLabel(value: string): string {
  return ({ 1: 'Original', 2: 'Retificadora', 3: 'Situação especial', 4: 'Retificadora especial' }[value] || 'Tipo informado')
}

function kindLabel(kind: string): string {
  return kind === 'RECIBO' ? 'Recibo de entrega' : 'Declaração'
}

function documentDownloadHref(path?: string | null): string {
  return resolveApiUrl(path || '', apiBase)
}

async function load(referenceId?: number) {
  if (!props.clientId) return
  const current = ++generation
  loading.value = true
  error.value = null
  try {
    const response = await fetchHistory(props.clientId, referenceId)
    if (current === generation) {
      history.value = response
      selectedReferenceId.value = referenceId || null
    }
  } catch (caught) {
    if (current === generation) {
      history.value = null
      error.value = apiErrorMessage(caught, 'Não foi possível carregar os documentos DEFIS locais.')
    }
  } finally {
    if (current === generation) loading.value = false
  }
}

function requestConsult(referenceId: number) {
  emit('consult', referenceId)
}

watch(() => [props.open, props.clientId] as const, ([open]) => {
  if (open) void load()
  else {
    generation++
    loading.value = false
    error.value = null
    history.value = null
    selectedReferenceId.value = null
  }
}, { immediate: true })
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="Declaração DEFIS e recibo"
    description="Escolha uma declaração já listada; abrir este modal não consulta a SERPRO."
    content-class="w-[calc(100vw-1rem)] sm:max-w-xl"
    test-id="defis-specific-declaration-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="space-y-4">
        <p class="font-medium text-highlighted">
          {{ clientName || `Cliente #${clientId || '—'}` }}
        </p>
        <UAlert v-if="error" color="error" :title="error">
          <template #actions>
            <UButton
              size="xs"
              color="neutral"
              variant="outline"
              label="Tentar novamente"
              @click="load(selectedReferenceId || undefined)"
            />
          </template>
        </UAlert>
        <ShellLoadingModalBody v-else-if="loading" :rows="2" />
        <template v-else>
          <div v-if="history?.references.length" class="divide-y divide-default rounded-lg border border-default">
            <div v-for="item in history.references" :key="item.reference_id" class="flex items-center justify-between gap-3 p-3">
              <div>
                <p class="text-sm font-medium text-highlighted">
                  {{ item.calendar_year }} · {{ typeLabel(item.declaration_type) }}
                </p>
                <p class="text-xs text-muted">
                  Referência local protegida
                </p>
              </div>
              <div class="flex gap-2">
                <UButton
                  size="xs"
                  color="neutral"
                  variant="outline"
                  label="Ver documentos"
                  @click="load(item.reference_id)"
                />
                <UButton
                  size="xs"
                  color="primary"
                  variant="subtle"
                  label="Consultar"
                  @click="requestConsult(item.reference_id)"
                />
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-muted">
            Nenhuma declaração DEFIS disponível para consulta específica.
          </p>

          <div v-if="selectedReferenceId && history?.documents.length" class="divide-y divide-default rounded-lg border border-default">
            <div v-for="item in history.documents" :key="item.id" class="flex items-center justify-between gap-3 p-3">
              <div>
                <p class="text-sm font-medium text-highlighted">
                  {{ kindLabel(item.kind) }}
                </p>
                <p class="text-xs text-muted">
                  PDF · {{ item.byte_size ? `${item.byte_size} bytes` : 'tamanho protegido' }}
                </p>
              </div>
              <UButton
                :to="documentDownloadHref(item.download_path)"
                external
                target="_blank"
                icon="i-lucide-download"
                label="Baixar"
                size="xs"
                color="neutral"
                variant="outline"
              />
            </div>
          </div>
          <p v-else-if="selectedReferenceId" class="text-sm text-muted">
            Nenhum documento armazenado para a declaração selecionada.
          </p>
          <p class="text-xs text-muted">
            Origem: {{ history?.provenance?.source || 'descritor local protegido' }}.
          </p>
        </template>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        :show-submit="false"
        @cancel="emit('update:open', false)"
      />
    </template>
  </ShellScrollableModal>
</template>
