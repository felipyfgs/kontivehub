<script setup lang="ts">
/**
 * Botão de abertura do assistente — visível só com auth + me.assistant.enabled.
 * Abre o sheet após refresh do /me; a API continua fail-closed no chat.
 */
const props = defineProps<{
  collapsed?: boolean
  /** Variante compacta (navbar). */
  compact?: boolean
}>()

const { isAuthenticated, refreshIdentity } = useSanctumAuth()
const {
  isAssistantSlideoverOpen,
  openAssistantSlideover,
  assistantAvailable
} = useDashboard()

const square = computed(() => !!(props.collapsed || props.compact))
const visible = computed(() => isAuthenticated.value && assistantAvailable.value)
const opening = ref(false)

async function onClick() {
  if (opening.value || !assistantAvailable.value) return
  opening.value = true
  try {
    await refreshIdentity().catch(() => {})
    openAssistantSlideover()
  } finally {
    opening.value = false
  }
}
</script>

<template>
  <UTooltip
    v-if="visible"
    text="Assistente"
    :shortcuts="['⇧', 'A']"
  >
    <UButton
      icon="i-lucide-sparkles"
      :label="square ? undefined : 'Assistente'"
      color="neutral"
      variant="ghost"
      :square="square"
      :block="!square"
      :loading="opening"
      :class="[!square && 'justify-start']"
      aria-label="Abrir assistente"
      data-testid="assistant-trigger"
      :aria-expanded="isAssistantSlideoverOpen"
      @click="onClick"
    />
  </UTooltip>
</template>
