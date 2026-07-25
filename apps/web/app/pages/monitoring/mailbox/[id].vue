<script setup lang="ts">
/**
 * Rota canônica do detalhe da mensagem.
 * Renderiza dentro do parent mailbox.vue (desktop) ou full route.
 */
const route = useRoute()
const router = useRouter()

const emit = defineEmits<{
  close: []
  triaged: []
}>()

const messageId = computed(() => Number(route.params.id))

function close() {
  emit('close')
  void router.push('/monitoring/mailbox')
}

function onTriaged() {
  emit('triaged')
}
</script>

<template>
  <MonitoringMailboxMail
    v-if="Number.isFinite(messageId) && messageId > 0"
    :message-id="messageId"
    show-close
    class="min-w-0 flex-1"
    @close="close"
    @triaged="onTriaged"
  />
  <div
    v-else
    class="flex flex-1 items-center justify-center p-8 text-sm text-muted"
  >
    ID de mensagem inválido.
  </div>
</template>
