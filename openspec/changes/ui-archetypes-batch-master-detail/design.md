## Context

O arquétipo `inbox.vue` usa navbar no painel mestre, detalhe adjacente no
desktop e slideover no mobile. Communication, Work e Caixa Postal aplicam essa
ideia com composições distintas: três painéis em Communication, três views em
Work e chrome fiscal acima do split em Mailbox. Apenas a navbar principal é
equivalente entre elas.

## Goals / Non-Goals

**Goals:**

- Reusar `ShellPageNavbar` somente nas três superfícies mestre equivalentes.
- Remover o collapse manual duplicado e preservar todos os slots e testids.
- Manter resize, seleção, detalhes, slideovers, atalhos e restauração de foco.

**Non-Goals:**

- Não criar `ShellMasterDetailWorkspace` ou trocar painéis estruturais.
- Não migrar navbars de detalhe, forbidden states ou toolbars.
- Não alterar rotas, views, composables, dados, matriz ou allowlists.

## Decisions

### Padronizar somente a navbar mestre

`CommunicationWorkspacePage`, `WorkQueueChrome` e o navbar full-width de
Mailbox usarão `ShellPageNavbar`. Badges e ações continuam em seus slots
atuais; somente o slot `#leading` com collapse será removido.

Alternativa: encapsular todo o workspace. Rejeitada porque os três layouts não
compartilham o mesmo número de painéis, detalhe ou comportamento responsivo.

### Preservar os containers atuais

O painel resizable de Communication, o toolbar de Work e o split interno de
Mailbox permanecem intactos. O navbar de Mailbox continua acima do painel
`mailbox-list`, portanto o detalhe não muda de ownership.

Alternativa: migrar também panels/toolbars para Shells. Rejeitada por ampliar o
delta sem comportamento comum e arriscar resize, kanban e slideovers.

### Provar composição e interação separadamente

Um gate focado verificará a navbar e a ausência de collapse duplicado. Os
testes existentes continuam cobrindo seleção, foco, paginação, atalhos,
autorização, views e responsividade.

## Risks / Trade-offs

- [Resize ou detalhe muda de hierarquia] → não mover nenhum panel, `NuxtPage`
  ou slideover; trocar apenas a tag navbar.
- [Foco não volta ao item mestre] → preservar refs, handlers e shortcuts sem
  alteração e rodar testes de foco.
- [Navbar incorreta é migrada] → limitar Communication ao branch autorizado e
  Mailbox ao chrome full-width externo ao split.
- [Collapse duplicado] → remover os três slots `#leading` correspondentes e
  verificar zero collapse direto nesses blocos.

## Migration Plan

1. Adicionar/ajustar gates focados.
2. Trocar as três navbars com hunks mínimos.
3. Rodar testes focados, seis gates e inspeção desktop/mobile/teclado.
4. Sincronizar, arquivar e marcar o Lote 4 no guarda-chuva.

Rollback restaura as três tags e seus collapses; não há estado persistido,
API, flag ou rollout.

## Open Questions

Nenhuma bloqueante.
