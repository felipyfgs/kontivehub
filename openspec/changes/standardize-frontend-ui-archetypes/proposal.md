## Why

O frontend já possui uma boa base Nuxt UI e passa o gate estrutural de 83 rotas, mas a auditoria completa encontrou deriva visual, lacunas de acessibilidade, responsividade inconsistente e trabalho de rede/bundle evitável. Padronizar essas superfícies agora reduz regressões e torna os arquétipos do dashboard, o sistema visual KontiveHub e os estados reais de produto contratos verificáveis em toda a aplicação.

## What Changes

- Formalizar os arquétipos de casca global, visão analítica, lista administrativa, master-detail, configurações e autenticação como contratos reutilizáveis do frontend.
- Preservar a Home operacional e enquadrá-la no arquétipo analítico, sem inventar gráficos, períodos ou dados que a API não fornece.
- Fixar a identidade visual canônica em verde Kontive, zinc, Public Sans e tokens semânticos; remover personalização arbitrária de paleta e a estética de gradiente/blur da autenticação.
- Padronizar semântica, teclado, foco, movimento reduzido, alvos de toque, copy pt-BR e estados de loading, erro, vazio, indisponibilidade e permissão.
- Transformar tabelas e modais densos em cards, detalhes expansíveis ou slideovers abaixo de `md`, inclusive nas superfícies administrativas globais.
- Impedir carregamento após redirecionamento por falta de permissão e eliminar requests de identidade/onboarding, conexões realtime e dependências pesadas quando não forem necessárias.
- Acrescentar cobertura de regressão estrutural, acessível, responsiva, de rede e de orçamento de bundle, mantendo os gates web existentes.

## Capabilities

### New Capabilities

- `frontend-ui-system-conformance`: Define a fidelidade aos arquétipos, tokens, componentes Shell, Home operacional, autenticação, copy e estados visuais reais.
- `frontend-accessible-responsive-interactions`: Define semântica, teclado, foco, movimento reduzido, alvos de toque e transformação responsiva das superfícies.
- `frontend-runtime-efficiency`: Define comportamento fail-closed de autorização no cliente, deduplicação de requests, ativação condicional de realtime e carregamento sob demanda de código pesado.

### Modified Capabilities

Nenhuma. Não existem specs principais registradas em `openspec/specs/` para modificar.

## Impact

- Afeta `apps/web/app` (layouts, páginas, componentes, composables, middleware, plugins, utilities e tema) e os testes/gates de `apps/web`.
- Mantém Nuxt 4 em SPA estática, Nuxt UI 4, Sanctum por cookie, Laravel como dono do domínio e ícones Lucide.
- Não altera contratos públicos da API nem adiciona dependências por padrão; qualquer necessidade de dados analíticos reais fica fora deste change até existir contrato Laravel aprovado.
- Exige coordenação com mudanças em andamento na comunicação para preservar alterações alheias em arquivos compartilhados.
