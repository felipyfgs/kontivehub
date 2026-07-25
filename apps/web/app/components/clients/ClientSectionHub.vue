<script setup lang="ts">
/**
 * @deprecated Hubs Fiscal/Integrações removidos da IA do cliente.
 * Mantido só por compatibilidade de imports legados.
 */
import type { RouteLocationRaw } from 'vue-router'

defineProps<{
  clientId: number
  title: string
  description: string
  cards?: Array<{ to: RouteLocationRaw, label: string, icon?: string, description?: string }>
}>()
</script>

<template>
  <UEmpty
    icon="i-lucide-layout-grid"
    :title="title"
    :description="description || 'Esta seção foi unificada em Configuração.'"
    data-testid="client-section-hub-legacy"
  />
</template>
