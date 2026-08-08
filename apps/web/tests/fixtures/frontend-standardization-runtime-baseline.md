# Baseline de runtime e chunks — standardize-frontend-ui-archetypes

## Build reproduzível

- Data: 2026-08-04; revisão: `e05dda7`.
- Comando: `corepack pnpm --dir apps/web run generate`.
- Ambiente: Nuxt `4.4.8`, Nitro `2.13.4`, Vite `7.3.6`, Vue `3.5.39`.
- Artefato: `apps/web/.output/public/_nuxt`.
- Resultado: 252 chunks JavaScript e 3 folhas CSS; os hashes não são contrato.

| Maior artefato | Tamanho (bytes) |
|---|---:|
| JavaScript | 628046 |
| CSS | 247130 |
| JavaScript | 218246 |
| JavaScript | 213249 |
| JavaScript | 198420 |
| JavaScript | 160777 |
| JavaScript | 94394 |

## Requests de bootstrap

Medição executada em Chromium headless sobre `http://127.0.0.1:3000`, com o
seed local, viewport 1440×900 e uma página fria por rota no mesmo contexto
autenticado. Foram contadas apenas requests de API/Sanctum; assets estáticos,
HMR e websocket ficaram fora do inventário.

| Rota | Requests API | Endpoints observados |
|---|---:|---|
| `/login` | 1 | onboarding status |
| `/` | 7 | onboarding, identidade, memberships, KPIs/departamentos de trabalho, resumo e inbox operacionais |
| `/clients` | 5 | onboarding, identidade, memberships, categorias e clientes |
| `/conta` | 3 | onboarding, identidade e memberships |
| `/communication/flows/1/editor` | 5 | onboarding, identidade, memberships, detalhe e catálogo de fluxos |

Em deep links sem tenant/permissão efetiva, `/communication/flows`, detalhe,
editor e `/communication/quick-responses` emitiram zero requests de catálogo,
detalhe ou editor. A identidade temporária criada somente para essa verificação
foi removida ao final.

## Comparação após a otimização

- Comando: `corepack pnpm --dir apps/web run generate`, no mesmo checkout e ambiente.
- Repetição final: 254 chunks JavaScript, 3 folhas CSS e entry de `555323`
  bytes no mesmo ambiente.
- Entry JavaScript: `628046` → `555323` bytes (`-72723`, redução de `11,58%`).
- Pusher/Echo deixaram o entry e passaram a dois chunks dinâmicos de `61915` e
  `11693` bytes, carregados somente após feature, sessão, permissão e tenant válidos.
- Vue Flow permaneceu isolado em um chunk de `198315` bytes.
- Unovis permaneceu isolado em chunks de `160803` e `56306` bytes.
- As entradas `optimizeDeps` de Vue Flow/Unovis foram mantidas: afetam o
  prebundle de desenvolvimento e a build comprovou que não fundem esses módulos
  ao entry de produção.
- A fachada `useApi` passou a memoizar por Sanctum client e inicializar a fábrica
  de cada domínio apenas no primeiro acesso, preservando a mesma interface tipada.

O build final concluiu com sucesso em 2026-08-04. A comparação de requests foi executada em
browser headless. Como não existia captura pré-otimização confiável, não se
atribui redução de rede ao lazy facade; o ganho aceito deste lote é o delta
reproduzível de chunks e de inicialização de fábricas, sem alegar uma queda de
requests inexistente.

O QA final preservou as contagens acima ao excluir `/lucide.json` e o próprio
POST de autenticação. Como controle adicional, `/monitoring/mailbox` emitiu 7
requests de API, restritas a identidade/tenant e aos três recursos reais do
módulo. Os registros locais `qa-results.json` e `qa-followup.json` confirmam
zero overflow horizontal nas rotas representativas; o follow-up terminou sem
page errors reproduzíveis.
