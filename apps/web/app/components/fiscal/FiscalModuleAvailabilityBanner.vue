<script setup lang="ts">
import type { FiscalModuleAdminItem } from '~/types/api'
import { fiscalControlModuleKey, fiscalModuleStateLabel } from '~/utils/fiscal-module-controls'

const props = defineProps<{
  moduleKey?: string | null
  surface?: string | null
}>()

const api = useApi()
const { sessionEpoch } = useDashboard()
const decision = ref<FiscalModuleAdminItem | null>(null)
const canonicalModuleKey = computed(() => fiscalControlModuleKey(props.moduleKey, props.surface))
const paused = computed(() => decision.value !== null && !decision.value.allowed)
const alertColor = computed(() =>
  decision.value?.state === 'TECHNICAL_FAILURE' ? 'error' as const : 'warning' as const
)
const description = computed(() => {
  const reason = decision.value?.reason?.trim()
  const prefix = reason ? `${reason} ` : ''
  return `${prefix}Os dados já armazenados continuam disponíveis; somente novas consultas manuais e automáticas estão pausadas.`
})

let loadSeq = 0
async function load() {
  if (!canonicalModuleKey.value) {
    decision.value = null
    return
  }
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  try {
    const response = await api.office.onboardingStatus()
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    decision.value = response.data.modules?.find(
      module => module.module_key === canonicalModuleKey.value
    ) || null
  } catch {
    if (seq === loadSeq && epoch === sessionEpoch.value) decision.value = null
  }
}

watch([canonicalModuleKey, sessionEpoch], load, { immediate: true })
</script>

<template>
  <UAlert
    v-if="paused && decision"
    :color="alertColor"
    variant="subtle"
    icon="i-lucide-pause-circle"
    :title="`Novas consultas pausadas · ${fiscalModuleStateLabel(decision.state)}`"
    :description="description"
    data-testid="fiscal-module-availability-banner"
  />
</template>
