<script setup lang="ts">
/**
 * Estado vazio do detalhe da Caixa Postal (nenhuma mensagem selecionada).
 */
</script>

<template>
  <div
    class="hidden flex-1 items-center justify-center lg:flex"
    data-testid="mailbox-empty-detail"
  >
    <div class="flex flex-col items-center gap-2 text-center">
      <UIcon
        name="i-lucide-inbox"
        class="size-24 text-dimmed"
      />
      <p class="text-sm text-muted">
        Selecione uma mensagem na lista para ver o detalhe.
      </p>
    </div>
  </div>
</template>
