<script setup lang="ts">
/**
 * Confirmação informada antes de refresh com snapshot recente (consome franquia).
 */
import { recentRefreshConfirmDescription } from '~/utils/monitor-commercial'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  lastAt?: string | null
  remaining?: number | null
  loading?: boolean
}>()

const emit = defineEmits<{
  confirm: []
}>()

const description = computed(() => recentRefreshConfirmDescription({
  lastAt: props.lastAt,
  remaining: props.remaining
}))
</script>

<template>
  <ShellConfirmModal
    v-model:open="open"
    title="Confirmar nova consulta"
    :description="description"
    confirm-label="Confirmar consulta"
    confirm-icon="i-lucide-cloud-download"
    :loading="loading"
    test-id="recent-refresh-confirm-modal"
    confirm-test-id="recent-refresh-confirm"
    @confirm="emit('confirm')"
  >
    <template #body>
      <UAlert
        color="warning"
        icon="i-lucide-coins"
        title="Consome 1 franquia — servidor pode bloquear se o intervalo mínimo não passou"
      />
    </template>
  </ShellConfirmModal>
</template>
