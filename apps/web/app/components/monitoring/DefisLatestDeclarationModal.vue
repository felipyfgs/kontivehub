<script setup lang="ts">
import type { DefisLatestDeclarationHistoryPayload } from '~/types/fiscal-modules'
import { resolveApiUrl } from '~/utils/api-url'

const props = defineProps<{ open: boolean, clientId: number | null, clientName?: string | null }>()
const emit = defineEmits<{ 'update:open': [value: boolean] }>()
const { fetchHistory } = useDefisLatestDeclarationMonitoring()
const apiBase = useRuntimeConfig().public.apiBase as string
const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<DefisLatestDeclarationHistoryPayload | null>(null)
let generation = 0

function kindLabel(kind: string) {
  return kind === 'RECIBO' ? 'Recibo de entrega' : 'Declaração'
}

function documentDownloadHref(path?: string | null): string {
  return resolveApiUrl(path || '', apiBase)
}

async function load() {
  if (!props.clientId) return
  const current = ++generation
  loading.value = true
  error.value = null
  try {
    const response = await fetchHistory(props.clientId)
    if (current === generation) history.value = response
  } catch (caught) {
    if (current === generation) {
      history.value = null
      error.value = apiErrorMessage(caught, 'Não foi possível carregar os documentos DEFIS locais.')
    }
  } finally {
    if (current === generation) loading.value = false
  }
}

watch(() => [props.open, props.clientId] as const, ([open]) => {
  if (open) void load()
  else {
    generation++
    loading.value = false
    error.value = null
    history.value = null
  }
}, { immediate: true })
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="Última DEFIS e recibo"
    description="Documentos já armazenados no cofre; abrir este modal não consulta a SERPRO."
    content-class="w-[calc(100vw-1rem)] sm:max-w-xl"
    test-id="defis-latest-declaration-modal"
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
              @click="load"
            />
          </template>
        </UAlert>
        <ShellLoadingModalBody v-else-if="loading" :rows="2" />
        <template v-else>
          <div v-if="history?.documents.length" class="divide-y divide-default rounded-lg border border-default">
            <div v-for="item in history.documents" :key="item.id" class="flex items-center justify-between gap-3 p-3">
              <div>
                <p class="text-sm font-medium text-highlighted">
                  {{ kindLabel(item.kind) }} · {{ item.calendar_year }}
                </p><p class="text-xs text-muted">
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
          <p v-else class="text-sm text-muted">
            Nenhum recibo ou declaração DEFIS armazenado para este cliente.
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
