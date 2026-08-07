# Auditoria transversal — standardize-frontend-ui-archetypes

Data: 2026-08-04. Referência visual validada: `31970177d818`.

## Tokens e cores

- Estados de produto em componentes e utilities usam tokens `primary`,
  `success`, `info`, `warning`, `error` e neutros do Nuxt UI.
- `#09090b` é a exceção canônica do canvas/theme-color/PWA escuro.
- A paleta hexadecimal de `client-category-colors.ts` representa cores de tags
  escolhidas pelo usuário, não estados do produto; por isso permanece como
  conteúdo configurável explicitamente delimitado.

## Tipografia e movimento

- Metadados operacionais de trabalho, documentos, clientes, monitoramento e
  comunicação foram elevados ao token `text-xs` (12 px).
- A única ocorrência remanescente abaixo de 12 px é a dica não interativa de
  atalho em `communication/Composer.vue`: ela fica oculta abaixo de `sm` e o
  arquivo está protegido pelo change `complete-whatsapp-message-composer`.
- Loaders `spin`/`pulse` param sob `prefers-reduced-motion`; transições visíveis
  alteradas usam `motion-reduce:transition-none` e preservam conteúdo, status e
  estado final.

## Responsividade e toque

- `ShellDataTable` transforma dados acionáveis em `ShellMobileCards` abaixo de
  `md`; identidade, estado, resumo, seleção, ações e detalhe são preservados.
- A exceção `mobile-cards=false` de Processos mantém o slot de árvore expandida,
  mas a mesma árvore já possui resumo/grid linearizado no mobile.
- As tabelas PGDAS-D que permanecem largas são regiões desktop nomeadas e têm
  cards/resumo estreito equivalentes; não escondem ações no mobile.
- Kanban, navegação da Conta, seletores de parcelamento e tabs do contexto de
  contato transformam ou quebram linha antes de `md`; não exigem scroll móvel
  para operar ações.
- Calendário, mailbox, cards expansíveis e controles móveis alterados usam alvo
  mínimo `min-h-11`/`min-w-11` (44 px).

## Formulários, mídia e overlays

- Autenticação, Conta, fluxos, administração e modais fiscais usam `UForm` e
  `UFormField` com `name`, label e erro do componente; o primeiro campo de
  modais editáveis usa autofocus quando necessário.
- Fotos de perfil, anexos, stickers, QR e conteúdo compartilhado têm `alt`
  factual; decoração continua sem anúncio redundante.
- `UModal`, `USlideover` e os Shells correspondentes fornecem trap, Escape e
  retorno de foco do Reka/Nuxt UI; mailbox restaura explicitamente o foco ao
  item que abriu o detalhe móvel.
- Regiões assíncronas compartilhadas expõem `aria-busy`, `role=status`, erro e
  retry; estados vazios e indisponíveis vêm da API, sem fallback sintético.

## Async, sessão e tenant

- Realtime remove listener online, timers, canais e transporte no dispose ou ao
  perder feature, sessão, permissão ou tenant.
- Requests alterados em módulos fiscais e histórico DAS usam sequência/epoch de
  sessão para ignorar resposta antiga; polling e subscriptions existentes têm
  cleanup no owner.
- Deep links sem tenant/permissão emitiram zero requests protegidas de catálogo,
  detalhe e editor no QA Playwright.

## Detector Impeccable

A passagem única encontrou duas bordas laterais de 2 px. A ocorrência alterada
em `TimelinePanel.vue` foi corrigida para a borda canônica de 1 px. A ocorrência
em `communication/Composer.vue` está fora do diff deste change e permanece sob
ownership explícito de `complete-whatsapp-message-composer`; não foi alterada.
