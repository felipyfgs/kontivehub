<script setup lang="ts">
/**
 * Exibe segredo de ativação uma única vez (link ou senha provisória).
 * Não persiste; limpar ao sair da tela pai.
 */
const props = defineProps<{
  activationUrl?: string | null
  temporaryPassword?: string | null
  expiresAt?: string | null
  method?: string | null
}>()

const toast = useToast()

const hasSecret = computed(() =>
  Boolean(props.activationUrl || props.temporaryPassword)
)

const secretValue = computed(() =>
  props.activationUrl || props.temporaryPassword || ''
)

const secretLabel = computed(() =>
  props.activationUrl ? 'Link de ativação' : 'Senha provisória'
)

async function copy() {
  if (!secretValue.value) return
  try {
    await navigator.clipboard.writeText(secretValue.value)
    toast.add({ title: 'Copiado.', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar.', color: 'warning' })
  }
}
</script>

<template>
  <div
    v-if="hasSecret"
    class="space-y-3 rounded-lg border border-default bg-elevated/40 p-4"
    data-testid="one-time-secret"
  >
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0 space-y-1">
        <p class="text-sm font-medium text-highlighted">
          {{ secretLabel }}
        </p>
        <p class="text-xs text-muted">
          Exibido uma vez. Ao fechar, use Regenerar acesso.
        </p>
        <p
          v-if="expiresAt"
          class="text-xs text-muted"
        >
          Expira em {{ formatDateTime(expiresAt) }}
        </p>
      </div>
      <UButton
        size="sm"
        color="neutral"
        variant="soft"
        icon="i-lucide-copy"
        label="Copiar"
        data-testid="one-time-secret-copy"
        @click="copy"
      />
    </div>
    <code
      class="block break-all rounded-md bg-default px-3 py-2 text-sm text-highlighted"
      data-testid="one-time-secret-value"
    >{{ secretValue }}</code>
  </div>
</template>
