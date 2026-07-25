<script setup lang="ts">
/**
 * Contratos — placeholder (Em breve).
 */
const { item } = useClientDetail()
</script>

<template>
  <div
    v-if="item"
    class="min-w-0 space-y-4"
    data-testid="client-page-contratos"
  >
    <ShellSectionHeader
      title="Contratos"
      description="Contratos e vínculos comerciais deste cliente."
      test-id="client-section-contratos"
    >
      <UBadge
        color="success"
        variant="subtle"
        label="Em breve"
      />
    </ShellSectionHeader>

    <UEmpty
      icon="i-lucide-file-text"
      title="Contratos em breve"
      description="Esta seção estará disponível em uma próxima versão."
    />
  </div>
</template>
