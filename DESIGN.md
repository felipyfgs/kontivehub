---
name: KontiveHub
description: "Mesa de Operações Confiável para o trabalho diário de escritórios contábeis."
colors:
  primary: "var(--ui-primary)"
  surface: "var(--ui-bg)"
  surface-muted: "var(--ui-bg-muted)"
  surface-elevated: "var(--ui-bg-elevated)"
  border: "var(--ui-border)"
  text: "var(--ui-text)"
  text-muted: "var(--ui-text-muted)"
  text-highlighted: "var(--ui-text-highlighted)"
  text-inverted: "var(--ui-text-inverted)"
  info: "var(--ui-info)"
  warning: "var(--ui-warning)"
  error: "var(--ui-error)"
typography:
  headline:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "normal"
  title:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.5
    letterSpacing: "normal"
  body:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.43
    letterSpacing: "normal"
  label:
    fontFamily: "Public Sans, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.33
    letterSpacing: "0.025em"
rounded:
  xs: "2px"
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
  2xl: "16px"
  full: "9999px"
spacing:
  "1": "4px"
  "1.5": "6px"
  "2": "8px"
  "2.5": "10px"
  "3": "12px"
  "4": "16px"
  "6": "24px"
  "12": "48px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.text-inverted}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  button-neutral-outline:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  input-outline:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-highlighted}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  badge-subtle:
    backgroundColor: "{colors.surface-elevated}"
    textColor: "{colors.text}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "4px 8px"
  card-subtle:
    backgroundColor: "{colors.surface-elevated}"
    textColor: "{colors.text}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "16px 24px"
  navigation-item:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "6px 10px"
  kpi-card:
    backgroundColor: "{colors.surface-elevated}"
    textColor: "{colors.text-highlighted}"
    typography: "{typography.headline}"
    rounded: "{rounded.lg}"
    padding: "16px"
  table-compact:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "4px 12px"
---

# Design System: KontiveHub

## Overview

**Creative North Star: "Mesa de Operações Confiável"**

O KontiveHub se comporta como uma mesa de trabalho preparada antes do início do
expediente: cada instrumento tem lugar, o estado da operação é legível e nada
compete com a próxima decisão. A personalidade é precisa, sóbria e operacional.
O sistema aceita alta densidade porque seus usuários varrem carteiras, prazos,
pendências e tarefas durante muitas horas, mas controla essa densidade com
hierarquia, truncamento e estados consistentes.

A identidade nasce do contraste entre o **Verde Kontive**, usado como sinal
funcional, e o **Grafite de Trabalho**, que sustenta superfícies, texto e
divisores em temas claro e escuro. O resultado deve parecer um produto
operacional maduro, não uma landing page SaaS: gradientes decorativos, excesso
de cards e efeitos sem função não pertencem a este sistema.

**Key Characteristics:**

- Denso sem ser apertado.
- Sóbrio, legível e orientado a evidências.
- Mobile-first, com transformação estrutural em vez de simples encolhimento.
- Verde reservado a ação, foco, seleção e confirmação.
- Bordas e camadas tonais antes de sombras.

## Colors

A paleta combina o Verde Kontive com uma escala zinc neutra e mode-aware do
Nuxt UI; estados semânticos preservam seus próprios canais de informação.

### Primary

- **Verde Kontive** (`primary`): ação principal, foco, seleção, item ativo e
  confirmação positiva. Sua escala canônica é a família verde definida em
  `apps/web/app/assets/css/main.css`.

### Neutral

- **Grafite de Trabalho** (`surface`, `surface-muted`, `surface-elevated`):
  superfícies em camadas que se invertem de forma coerente entre os temas claro
  e escuro.
- **Grafite de Leitura** (`text`, `text-muted`, `text-highlighted`): hierarquia
  textual baseada em contraste, nunca em novas cores decorativas.
- **Linha de Estrutura** (`border`): divisores, contornos e agrupamentos
  compactos.

### Estados semânticos

- **Informação** (`info`): contexto informativo sem urgência.
- **Atenção** (`warning`): prazo, risco ou revisão necessária.
- **Erro** (`error`): falha, bloqueio ou ação destrutiva.

**A Regra do Verde Funcional.** O Verde Kontive aparece quando há ação, foco,
seleção ou confirmação; ele não colore grandes superfícies por decoração.

**A Regra do Estado Literal.** Cores de informação, atenção e erro representam
um estado real do domínio. Nunca escolha uma delas apenas para variar a paleta.

## Typography

**Display Font:** Public Sans (com fallback sans-serif)

**Body Font:** Public Sans (com fallback sans-serif)

**Character:** Public Sans oferece leitura neutra e firme em tabelas, filtros,
formulários e números. O sistema usa peso e escala com moderação; não depende de
uma fonte de display nem de títulos monumentais para produzir hierarquia.

### Hierarchy

- **Headline** (600, `headline`, line-height 1.25): valores de KPI e títulos de
  maior prioridade operacional.
- **Title** (600, `title`, line-height 1.5): títulos de cards, modais, seções e
  navegação.
- **Body** (400, `body`, line-height 1.43): células, descrições, campos e texto
  de interface.
- **Label** (500, `label`, tracking discreto): metadados, legendas e rótulos
  curtos. Uppercase é reservado a faixas de KPI e pequenos agrupadores.

Números de KPI, totais e paginação usam algarismos tabulares. Textos em espaços
operacionais estreitos são truncados com acesso preservado ao conteúdo por
contexto, título ou detalhe.

**A Regra da Varredura.** A hierarquia deve permitir reconhecer título, estado,
valor e próxima ação antes de exigir leitura contínua.

## Layout

O sistema é mobile-first sobre uma unidade espacial de 4px. A densidade usual
usa 6px a 16px entre controles e 16px a 24px entre blocos; separações de 48px
ficam reservadas a layouts amplos de configuração. Conteúdo confortável usa
largura máxima de 64rem, conteúdo amplo chega a 72rem e superfícies
operacionais podem ocupar a largura total.

Os breakpoints canônicos são `sm` (640px), `md` (768px), `lg` (1024px) e `xl`
(1280px). Toolbars empilham antes de `sm`; tabelas viram cards abaixo de `md`;
navegação de seção troca tabs por select abaixo de `lg`. Master-detail usa
painéis irmãos no dashboard e se converte em foco único ou slideover quando a
largura não sustenta duas regiões.

Faixas de KPI começam em duas colunas e chegam a duas, três, quatro, cinco ou
seis colunas em `lg`. No desktop, a faixa pode se tornar contínua com gap de
1px e apenas os cantos externos arredondados. Tabelas usam layout fixo,
identidade flexível, colunas secundárias controladas e rolagem horizontal
apenas como escape hatch.

**A Regra da Transformação Responsiva.** Mobile não é desktop comprimido:
tabela vira card, tabs viram select e ações mantêm ícone e nome acessível quando
o rótulo visual precisa desaparecer.

## Elevation & Depth

O sistema é plano em camadas. A profundidade cotidiana vem de superfícies
`default`, `muted` e `elevated`, mais bordas de um pixel. `shadow-xs` sinaliza
raros itens interativos; `shadow-lg` pertence a modais, menus flutuantes e rails
que realmente se destacam do plano. Cards de conteúdo não ganham sombra para
parecer mais importantes.

### Shadow Vocabulary

- **Contato Interativo** (`shadow-xs`): separação mínima para itens arrastáveis
  ou bolhas que precisam se destacar do fundo imediato.
- **Plano Flutuante** (`shadow-lg`): modal, popover, viewport de navegação ou
  rail sobreposto.

**A Regra da Camada Antes da Sombra.** Tente primeiro fundo tonal e borda. Use
sombra apenas quando o elemento ocupa fisicamente um plano flutuante.

## Shapes

O raio base do Nuxt UI é 4px e produz uma escala de cantos de 2px a 16px.
Controles e badges usam cantos discretos de 6px; cards, accordions, cabeçalhos
de tabela e modais usam 8px; composer e bolhas específicas de comunicação podem
usar 12px ou 16px. Círculos completos ficam restritos a avatar, indicador,
ícone tonal de KPI e botão explicitamente circular.

Bordas são finas e estruturais. Field groups removem cantos internos,
faixas contínuas preservam somente os cantos externos e bolhas de conversa
podem quebrar simetria para indicar direção. Essas exceções pertencem ao
domínio, não redefinem a forma global.

**A Regra do Canto com Função.** O raio comunica agrupamento e escala; não
arredonde cada região apenas para transformá-la em mais um card.

## Components

Os componentes são instrumentos compactos, com estados claros e ações
previsíveis. Nuxt UI fornece a base; wrappers `Shell*` preservam a gramática do
produto.

### Buttons

- **Shape:** cantos compactos (`rounded.md`) e texto medium.
- **Primary:** Verde Kontive sólido para a ação principal do contexto.
- **Secondary:** neutro outline para ações paralelas; neutral ghost para ações
  contextuais e cancelamento.
- **Destructive:** canal `error`, nunca Verde Kontive com cópia destrutiva.
- **Hover / Focus:** mudança tonal curta e outline de foco visível com o canal
  semântico da ação.
- **Responsive:** rótulos podem ocultar abaixo de `sm`, mas ícone e
  `aria-label` permanecem.

### Chips

- **Style:** badges de estado usam variantes soft ou subtle; filtros ativos
  usam field group neutral outline com ação de editar e ação de remover.
- **State:** seleção e severidade são expressas por canal semântico, borda e
  peso do texto; o chip não vira mini-card.

### Cards / Containers

- **Corner Style:** canto de seção (`rounded.lg`).
- **Background:** camada elevada suave.
- **Shadow Strategy:** sem sombra em repouso.
- **Border:** ring ou borda default quando a separação estrutural é necessária.
- **Internal Padding:** 16px no mobile e 24px a partir de `sm`.

`ShellSectionCard` é o contêiner canônico de seção breve.
`ShellPanelAccordion` organiza painéis secundários empilhados; não substitua
cada painel por uma nova família de cards.

### Inputs / Fields

- **Style:** fundo default, ring inset accented, canto de controle
  (`rounded.md`) e altura compacta.
- **Focus:** outline e ring no canal semântico do campo.
- **Error / Disabled:** erro usa o canal `error`; disabled reduz opacidade e
  mantém o estado legível, sem parecer carregamento.
- **Search:** largura total no mobile e largura flexível com teto no desktop.

### Navigation

A sidebar vertical, a command palette e a navegação contextual usam Public
Sans em tamanho body, ícones de 20px e fundo elevado no hover/ativo. A sidebar
é colapsável e preserva tooltip; tabs horizontais podem rolar, e em mobile a
navegação de seção vira select de altura mínima confortável para toque.

### KPI Strip

O KPI canônico usa card subtle, rótulo curto em uppercase, valor semibold com
algarismos tabulares e ícone dentro de círculo tonal. Em `lg`, os cards formam
uma única faixa; alerta crítico acrescenta ícone e canal de severidade sem
colorir toda a composição.

### Data Tables

`ShellDataTable` é o padrão de listas. Cabeçalhos e células reduzem o padding
do Nuxt UI para operação densa, preservam texto body e divisores claros. A
paginação oferece 10, 20 ou 50 linhas, com 20 como padrão. Abaixo de `md`, o
conteúdo vira cards; tabela horizontal no telefone exige justificativa
explícita.

### Dialogs

Formulários e confirmações usam modal com largura típica de 28rem, divisão
entre header, body e footer, cancelar neutro e confirmar à direita. A sombra
forte é aceita porque o modal ocupa um plano flutuante. Editores complexos de
filtro podem usar fullscreen no mobile.

## Do's and Don'ts

### Do:

- **Do** reutilize `ShellPagePanel`, `ShellPageNavbar`, `ShellDataTable`,
  `ShellSectionCard`, `ShellPanelAccordion` e modais `Shell*`.
- **Do** use Verde Kontive para ação, foco, seleção e confirmação verificável.
- **Do** preserve foco visível, alvos adequados para toque, `aria-label` e
  comportamento por teclado.
- **Do** transforme tabelas em cards e tabs em select nos breakpoints canônicos.
- **Do** use truncamento, `min-width: 0` e algarismos tabulares para sustentar
  densidade sem quebrar o layout.

### Don't:

- **Don't** faça a interface parecer uma landing page SaaS com gradientes
  decorativos, hero promocional ou efeitos sem função.
- **Don't** envolva cada grupo em um card nem aninhe cards quando borda,
  espaçamento ou accordion resolvem a hierarquia.
- **Don't** use sombras em cards de repouso ou elevação para compensar uma
  hierarquia fraca.
- **Don't** introduza novas cores para “dar variedade”; estados usam os canais
  semânticos existentes.
- **Don't** comprima tabelas desktop no telefone nem force largura mínima que
  produza rolagem horizontal como padrão.
