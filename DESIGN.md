---
name: KontiveHub
description: Central operacional multi-tenant para escritórios contábeis.
colors:
  primary: "#00C16A"
  primary-hover: "#00A155"
  primary-strong: "#007F45"
  primary-soft: "#EFFDF5"
  success: "var(--ui-success)"
  info: "var(--ui-info)"
  warning: "var(--ui-warning)"
  error: "var(--ui-error)"
  canvas: "#FFFFFF"
  surface: "#FAFAFA"
  surface-elevated: "#F4F4F5"
  border: "#E4E4E7"
  text-muted: "#71717A"
  text: "#3F3F46"
  text-strong: "#18181B"
  canvas-dark: "#09090B"
typography:
  display:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "-0.0125em"
  title:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.5
  body:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.4286
  control:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.4286
  label:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.3333
    letterSpacing: "0.025em"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
  2xl: "16px"
  full: "9999px"
spacing:
  xs: "6px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  2xl: "48px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.canvas}"
    typography: "{typography.control}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
    height: "36px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.canvas}"
    rounded: "{rounded.md}"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.text}"
    typography: "{typography.control}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
    height: "36px"
  input-default:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.text-strong}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
    height: "36px"
  card-subtle:
    backgroundColor: "{colors.surface-elevated}"
    textColor: "{colors.text-strong}"
    rounded: "{rounded.lg}"
    padding: "16px"
  navigation-active:
    backgroundColor: "{colors.surface-elevated}"
    textColor: "{colors.primary-hover}"
    typography: "{typography.control}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
  badge-soft:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.primary-strong}"
    typography: "{typography.label}"
    rounded: "{rounded.full}"
    padding: "2px 8px"
---

# Design System: KontiveHub

## Overview

**Creative North Star: "Central Operacional do Escritório"**

KontiveHub combina a organização espacial de uma sala de operações com a atenção contínua de uma central fiscal. A interface deve permitir que equipes contábeis examinem carteira, trabalho, documentos, comunicação e estados fiscais com rapidez, sem parecer fria ou hostil. A densidade é pragmática e técnica; a linguagem, os vazios e os estados mantêm o sistema claro e humano.

O dashboard de referência em `.local/references/dashboard` é a autoridade estrutural: hierarquia de painéis do Nuxt UI, sidebar colapsável e redimensionável, navegação vertical, toolbars, tabelas, filtros, overlays e transformação responsiva devem conservar seus arquétipos. A identidade evita a estética de fintech chamativa: não usa neon, glassmorphism, gradientes saturados, brilho especulativo ou ornamentação financeira para simular confiança.

**Key Characteristics:**

- Operação compacta, escaneável e orientada a estados reais.
- Verde Kontive reservado a ação, seleção, progresso e confirmação.
- Neutros zinc sustentando conteúdo, estrutura e densidade.
- Camadas tonais e bordas como profundidade padrão.
- Componentes reutilizáveis como unidade de consistência.
- Public Sans e ícones Lucide como linguagem direta e legível.
- Responsividade que transforma tabelas e painéis, sem apenas comprimi-los.

## Colors

A paleta é funcional: Verde Kontive conduz ação e estado; os neutros organizam a maior parte da superfície para que dados e exceções mantenham prioridade.

### Primary

- **Verde Kontive** (`primary`): ação principal, navegação ativa, foco, progresso e confirmação. É a voz de decisão do produto, não um preenchimento decorativo.
- **Verde Kontive Ativo** (`primary-hover`): hover, press e ênfase interativa em superfícies claras.
- **Verde Kontive Profundo** (`primary-strong`): texto e ícones sobre fundos verdes suaves, preservando contraste.
- **Névoa Kontive** (`primary-soft`): fundos sutis, estados selecionados e áreas institucionais de baixa intensidade.

### Semantic Status

- **Confirmação** (`success`): conclusão, disponibilidade e resultado positivo comprovado.
- **Informação** (`info`): contexto operacional que pede atenção sem indicar risco.
- **Atenção** (`warning`): pendência, prazo, indisponibilidade parcial ou decisão com cautela.
- **Erro** (`error`): falha, bloqueio, atraso crítico ou ação destrutiva.

### Neutral

- **Canvas de Trabalho** (`canvas`): fundo principal de leitura e operação.
- **Superfície de Apoio** (`surface`): cards sutis, formulários e agrupamentos sem elevação aparente.
- **Plano Elevado** (`surface-elevated`): sidebar, filtros, cabeçalhos de tabela e estados de hover.
- **Linha Estrutural** (`border`): separadores, rings e limites de painéis.
- **Texto de Contexto** (`text-muted`): descrições, metadados e pistas secundárias.
- **Texto Operacional** (`text`): labels, navegação e conteúdo corrente.
- **Texto de Decisão** (`text-strong`): títulos, valores e informação prioritária.
- **Canvas Noturno** (`canvas-dark`): base do modo escuro e cor de fundo do PWA.

**The Green Means Action Rule.** Verde Kontive aparece quando há ação, seleção ou estado relevante; uma tela majoritariamente verde perdeu a hierarquia.

**The Zinc Carries the Work Rule.** Dados, containers, divisores e navegação repousam nos neutros; a ausência de cor é o que dá autoridade aos sinais semânticos.

**The No Flashy Fintech Rule.** Não introduza neon, glow, vidro, gradientes de alta saturação ou gráficos ornamentais para comunicar modernidade.

## Typography

**Display Font:** Public Sans (with sans-serif fallback)
**Body Font:** Public Sans (with sans-serif fallback)

**Character:** Public Sans mantém o produto objetivo, familiar e legível em grandes volumes de dados. A hierarquia depende de peso, tamanho e espaço; não de múltiplas famílias tipográficas ou efeitos decorativos.

### Hierarchy

- **Display** (`display`): títulos institucionais e autenticação; no maior breakpoint pode crescer até `2.25rem` sem virar linguagem promocional.
- **Headline** (`headline`): título principal de página, painel ou área de trabalho.
- **Title** (`title`): título de card, seção e identidade de registro.
- **Body** (`body`): densidade padrão de conteúdo, formulários, navegação e tabelas.
- **Control** (`control`): botões, navegação e comandos compactos que exigem peso médio.
- **Label** (`label`): metadados, badges, cabeçalhos compactos e legendas; caixa alta é reservada a rótulos curtos como KPIs.

Números comparáveis em tabelas, KPIs e contagens usam algarismos tabulares. Textos secundários podem reduzir contraste, mas não tamanho abaixo do necessário para leitura operacional.

**The Scan First Rule.** Cada superfície deve revelar título, estado, valor e ação nessa ordem antes de oferecer explicação longa.

**The One Family Rule.** Public Sans é suficiente para toda a aplicação; não acrescente fonte display, manuscrita ou mono como ornamento.

## Layout

A casca global preserva a cadeia `UDashboardGroup` → `UDashboardSidebar`/`UDashboardPanel` → navbar/toolbar/body/footer. A sidebar é colapsável e redimensionável no desktop; menus usam tooltips ou popovers quando colapsados. Conteúdo central usa larguras confortáveis de `max-w-5xl` ou `max-w-6xl` quando a tarefa pede leitura, e largura livre quando tabelas ou master-detail precisam do espaço.

O ritmo primário usa 8, 12, 16, 24 e 48 pixels. Densidade compacta aparece dentro de tabelas, filtros e listas; separação maior marca mudança de seção, não cada novo container. Cabeçalhos e ações aceitam wrap antes de truncar comandos importantes.

Breakpoints seguem Tailwind: `sm` em 640px, `md` em 768px, `lg` em 1024px e `xl` em 1280px. Abaixo de `md`, tabelas complexas tornam-se cards de resumo expansíveis. Navegação seccional troca abas por seletor móvel; master-detail leva o detalhe a slideover; toolbars empilham busca e ações mantendo alvos de toque adequados.

**The Panel Chain Rule.** Não quebre a hierarquia de painel do arquétipo com wrappers visuais sem função ou shells paralelos.

**The Density With Air Rule.** Compacte controles e dados relacionados; use espaço maior apenas para separar decisões ou contextos diferentes.

**The Transform, Don't Shrink Rule.** Responsividade muda a composição — tabela para cards, detalhe para slideover, tabs para select — em vez de reduzir tudo até caber.

## Elevation & Depth

O padrão do template é estratificação discreta. Superfícies em repouso são separadas por tonalidade, bordas e rings; sombras não são decoração permanente. Elevação aparece somente quando comunica sobreposição, foco espacial ou mudança temporária de plano.

### Shadow Vocabulary

- **Contato Sutil** (`0 1px 2px rgb(0 0 0 / 0.05)`): controles ou elementos compactos que precisam se destacar minimamente do plano.
- **Overlay Operacional** (`0 10px 15px -3px rgb(0 0 0 / 0.10), 0 4px 6px -4px rgb(0 0 0 / 0.10)`): menus, popovers, modais, slideovers e painéis temporariamente expandidos.

**The Flat-by-Default Rule.** Cards e painéis ficam planos em repouso; sombras são consequência de interação ou sobreposição.

**The Border Before Shadow Rule.** Se uma borda ou mudança tonal expressar a hierarquia, não acrescente sombra.

## Shapes

A forma é suavemente técnica: controles compactos usam cantos médios (`rounded.md`), cards e grupos usam cantos moderados (`rounded.lg`), e superfícies especiais podem avançar para `rounded.xl` ou `rounded.2xl`. Pills (`rounded.full`) ficam restritas a badges, avatares, indicadores e controles que realmente representam uma cápsula.

Bordas finas e rings semânticos definem limites. Ícones são Lucide, normalmente entre 16 e 24 pixels, com traço consistente. Círculos comunicam avatar, status pontual ou ação iconográfica; não substituem containers funcionais.

**The Radius Ladder Rule.** Controles usam 6px, containers usam 8px e formas maiores são exceções justificadas pelo componente; não misture raios arbitrários na mesma superfície.

**The Pill Has Meaning Rule.** Cápsulas representam estado, filtro ou identidade curta; não transformam títulos, cards e botões comuns em pills.

## Components

Componentes são compactos, diretos e humanos. Toda recorrência deve primeiro procurar um primitivo Nuxt UI, um componente `Shell*` ou uma utility canônica antes de criar markup local. Reuso significa preservar hierarquia, slots, tokens, estados, acessibilidade e comportamento responsivo — não apenas compartilhar classes.

### Buttons

- **Shape:** retângulo suavemente curvo (`rounded.md`) com alvo adequado ao contexto.
- **Primary:** Verde Kontive, texto branco e peso médio; uma única ação principal por agrupamento.
- **Hover / Focus:** escurecimento para Verde Kontive Ativo e ring de foco visível; transições curtas respeitam `prefers-reduced-motion`.
- **Secondary / Ghost:** neutros, fundo transparente em repouso e superfície elevada no hover.
- **Icon-only:** rótulo acessível obrigatório; use versão quadrada e compacta apenas quando o ícone for inequívoco.

### Chips

- **Style:** fundo semântico suave, texto de maior contraste e formato capsule.
- **State:** cor comunica estado real; filtros selecionados também precisam de texto ou ícone, nunca apenas cor.
- **Density:** badges em células podem ocupar toda a largura quando isso estabiliza a leitura da coluna.

### Cards / Containers

- **Corner Style:** cantos moderados (`rounded.lg`).
- **Background:** canvas ou superfície sutil conforme o agrupamento.
- **Shadow Strategy:** plano por padrão; overlays seguem o vocabulário de elevação.
- **Border:** linha estrutural para delimitação quando a mudança tonal não for suficiente.
- **Internal Padding:** 16px como base; 24px em áreas amplas e 12px em containers compactos.

### Inputs / Fields

- **Style:** canvas claro, ring estrutural, altura compacta e texto operacional.
- **Focus:** ring Verde Kontive visível sem glow ornamental.
- **Error / Disabled:** mensagem explícita e semântica; desabilitado reduz ênfase sem apagar legibilidade.
- **Composition:** label, ajuda, erro e controle permanecem no mesmo componente de campo; formulários usam schema e estados reais.

### Navigation

Sidebar vertical, grupos expansíveis e itens ativos usam mudança tonal mais Verde Kontive. No desktop, navegação contextual pode usar duas linhas; no mobile, converte-se em seletor. Estado colapsado preserva ícone, tooltip e acesso por teclado.

### Tables and Lists

Tabelas usam cabeçalho tonal, divisores leves, densidade compacta, algarismos tabulares e ações contextuais no fim da linha. Seleção, ordenação, filtros, paginação e contagem pertencem ao mesmo sistema. Abaixo de `md`, conteúdos complexos usam cards móveis com identidade, estado, resumo, detalhes expansíveis e ações.

### KPI Strip

KPIs usam cards sutis em grid de duas colunas no mobile e composição contínua no desktop. Ícones ficam em círculos semânticos suaves; labels são curtas, valores usam peso e algarismos tabulares, e variações nunca dependem somente de cor.

**The Reuse Before Markup Rule.** Antes de criar um componente visual, verifique Nuxt UI, `components/shell`, `utils/page-card.ts`, `utils/table-ui.ts`, `utils/list-filter-layout.ts` e o arquétipo correspondente.

**The Same Contract Rule.** Um componente reutilizável encapsula também slots, estados, responsividade, foco e semântica; não aceite abstrações que compartilhem apenas aparência.

**The Real State Rule.** Loading, erro, vazio, sucesso, indisponibilidade e permissão vêm de dados reais; nunca invente fallback visual que pareça informação válida.

## Do's and Don'ts

### Do:

- **Do** preserve os arquétipos do dashboard para casca global, visão analítica, listas administrativas, master-detail e configurações.
- **Do** use tokens semânticos do Nuxt UI e a escala Verde Kontive definida no tema.
- **Do** reutilize componentes `Shell*` e utilities canônicas quando o contrato visual e interativo coincidir.
- **Do** componentize padrões recorrentes com props tipadas, slots claros, estados acessíveis e comportamento responsivo completo.
- **Do** mantenha ações, estados e erros compreensíveis em português do Brasil.
- **Do** compare alterações visuais em desktop e mobile, incluindo teclado, foco, loading, erro e vazio.

### Don't:

- **Don't** adicione estética de fintech chamativa: neon, glow, glassmorphism, gradientes saturados, números gigantes sem contexto ou gráficos decorativos.
- **Don't** crie uma segunda casca visual ou altere a cadeia de painéis do template.
- **Don't** espalhe cores, paddings, raios e variantes arbitrários em componentes de domínio.
- **Don't** transforme cada bloco em card elevado; agrupamento deve responder à estrutura da tarefa.
- **Don't** esconda ação ou estado exclusivamente por cor, hover ou viewport desktop.
- **Don't** copie branding, mocks, usuários, links ou regras de negócio do dashboard de referência.
