## Why

O frontend Nuxt já possui uma biblioteca `Shell*` e a skill `ui-archetypes` com
referência local validada (`.local/references/dashboard`, revisão
`31970177d818`), mas a adoção é desigual: cerca de 35% das páginas estão
conformes, 53% parciais e 5% divergentes. O maior gap é chrome duplicado —
dezenas de telas montam `UDashboardNavbar`/`UDashboardPanel` à mão onde
`ShellPagePanel`, `ShellPageNavbar` e `ShellSettingsShell` já existem.

Sem um inventário canônico e um roadmap de lotes, cada mudança de UI tende a
ampliar a divergência ou a criar Shell* novos sem necessidade. Esta change
consolida a auditoria e define a sequência de padronização; a implementação
fica em changes filhos, um por lote.

## What Changes

- Publicar o relatório de fidelidade em `audit.md` (inventário Shell*,
  classificação das 74 páginas por arquétipo, top-10 divergências, cobertura
  dos gates e achados secundários).
- Definir o roadmap operacional em `tasks.md` com seis lotes ordenados e o
  processo por lote (change OpenSpec próprio → menor delta → gates web).
- Declarar explicitamente que **este change não altera código** `.vue`/`.ts`,
  não amplia allowlists do gate de fidelidade, não remove componentes e não
  cria novos gates.
- Registrar que a referência local passou no script determinístico da skill
  nesta sessão e que a padronização é deduplicação/fidelidade — as cascas de
  produto já estão na allowlist de `test:fidelity`.

## Capabilities

### New Capabilities

- `ui-archetypes-audit`: Inventário canônico de fidelidade visual do
  `apps/web` contra os arquétipos do dashboard de referência, com classificação
  por página e critérios de conformidade/parcial/divergente.
- `ui-archetypes-batch-roadmap`: Sequência aprovável de lotes de
  padronização (admin, monitoring, dashboards, master-detail, docs, quick
  wins), cada um exigindo change OpenSpec próprio antes da implementação.

### Modified Capabilities

Nenhuma. Ainda não há specs principais de UI registradas em `openspec/specs`.

## Impact

- Código afetado neste change: nenhum. Apenas artefatos em
  `openspec/changes/ui-archetypes-standardization/`.
- Apps/domínios futuros: `apps/web` (páginas, `components/shell`, cascas de
  domínio em monitoring/docs/communication/work/admin). Sem impacto em
  `apps/api` ou `apps/wazync`.
- Contratos públicos: nenhum. Sem mudança em `/api/v1`, OpenAPI ou clientes
  HTTP.
- Gates: nenhum gate novo; lotes futuros devem passar `lint`, `typecheck`,
  `generate`, `test`, `test:fidelity` e `test:artifacts` no container
  `frontend-dev`.
- Riscos: nenhum neste ciclo. Em lotes futuros, risco de regressão visual
  mitigado por menor delta, skill `ui-archetypes` e gates existentes —
  **sem** ampliar allowlists sem autorização explícita.
- Fora de escopo: redesign, novos Shell* sem evidência de repetição,
  remoção de chrome proibido, novos gates de cores/a11y, alinhamento do
  comentário de commit do template no gate (`0f30c09` vs `31970177d818`).
