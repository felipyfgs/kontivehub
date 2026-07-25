<script setup lang="ts">
/**
 * Catálogo de contatos — rota fina sobre o catálogo de domínio.
 * Arquétipo: lista de gestão do dashboard.
 */
import { canViewCommunication } from '~/utils/permissions'

const { me } = useDashboard()

if (!canViewCommunication(me.value)) {
  await navigateTo('/')
}
</script>

<template>
  <CommunicationContactsCatalog />
</template>
