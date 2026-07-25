<template>
  <section>
    <ShellSectionHeader
      title="Execuções"
      description="Observabilidade operacional do fluxo."
    />
    <ShellSectionCard>
      <UAlert
        color="info"
        variant="subtle"
        icon="i-lucide-info"
        title="Observabilidade de execuções indisponível"
        description="A observabilidade de execuções ainda não está disponível nesta tela. A API atual não oferece uma fonte autorizada para listar ou detalhar execuções deste fluxo."
        data-testid="communication-flow-runs-unavailable"
      />
    </ShellSectionCard>
  </section>
</template>
