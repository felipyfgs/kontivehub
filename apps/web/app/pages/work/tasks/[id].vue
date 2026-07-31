<script setup lang="ts">
/**
 * Tarefa selecionada na fila: `/work/tasks/{id}`
 * Recurso no path (padrão do painel); filtros permanecem na sessão.
 */
import { parsePositiveRouteId } from '~/utils/route-params'

const route = useRoute()
const taskId = parsePositiveRouteId(route.params.id)
if (!taskId) {
  await navigateTo('/work/tasks', { replace: true })
}
</script>

<template>
  <WorkQueueWorkspace />
</template>
