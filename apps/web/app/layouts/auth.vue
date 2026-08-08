<script setup lang="ts">
/**
 * Layout de autenticação — arquétipo Auth (Nuxt UI AuthForm / PageCard).
 * Contêiner único responsivo: branding (lg+) + coluna do formulário.
 * Fora do shell autenticado; largura do form limitada a max-w-md nas páginas.
 */
const year = new Date().getFullYear()
</script>

<template>
  <div class="min-h-dvh lg:grid lg:grid-cols-2 bg-default">
    <!-- Painel de marca (desktop) -->
    <aside
      class="hidden min-h-dvh flex-col justify-between border-r border-default bg-elevated/50 p-10 lg:flex xl:p-14"
      aria-hidden="true"
    >
      <div>
        <div class="inline-flex items-center gap-2.5">
          <span class="flex size-10 items-center justify-center rounded-lg bg-primary/15 ring-1 ring-primary/25">
            <UIcon
              name="i-lucide-receipt"
              class="size-5 text-primary"
            />
          </span>
          <div class="min-w-0">
            <p class="font-semibold text-highlighted leading-tight">
              KontiveHub
            </p>
            <p class="text-xs text-muted">
              Gestão fiscal para escritórios contábeis
            </p>
          </div>
        </div>
      </div>

      <div class="max-w-md space-y-6">
        <h2 class="text-3xl xl:text-4xl font-semibold text-highlighted text-pretty tracking-tight">
          Painel do escritório
        </h2>
        <p class="text-base text-muted text-pretty leading-relaxed">
          Fiscal, documentos, trabalho e exportações — por escritório.
        </p>
        <ul class="space-y-3 text-sm text-muted">
          <li class="flex items-start gap-2.5">
            <UIcon
              name="i-lucide-shield-check"
              class="size-4 mt-0.5 text-primary shrink-0"
            />
            <span>Só equipe do escritório</span>
          </li>
          <li class="flex items-start gap-2.5">
            <UIcon
              name="i-lucide-lock-keyhole"
              class="size-4 mt-0.5 text-primary shrink-0"
            />
            <span>Sessão com CSRF e cookies seguros</span>
          </li>
          <li class="flex items-start gap-2.5">
            <UIcon
              name="i-lucide-building-2"
              class="size-4 mt-0.5 text-primary shrink-0"
            />
            <span>Dados isolados por escritório</span>
          </li>
        </ul>
      </div>

      <p class="text-xs text-muted">
        © {{ year }} KontiveHub
      </p>
    </aside>

    <!-- Formulário -->
    <div class="flex min-h-dvh min-w-0 flex-col">
      <header class="flex shrink-0 items-center justify-between gap-3 p-4 sm:p-6 lg:justify-end">
        <div class="inline-flex min-w-0 items-center gap-2 lg:hidden">
          <UIcon
            name="i-lucide-receipt"
            class="size-5 shrink-0 text-primary"
          />
          <span class="truncate font-semibold text-sm">KontiveHub</span>
        </div>
        <UColorModeButton aria-label="Alternar tema claro ou escuro" />
      </header>

      <main class="flex min-w-0 flex-1 flex-col items-center justify-center px-4 pb-10 sm:px-6">
        <div class="w-full max-w-md min-w-0">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>
