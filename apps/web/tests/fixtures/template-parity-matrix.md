# Matriz de paridade estrutural do template

Inventário canônico do gate estrutural do Nuxt UI Dashboard. `SHELL` exige
chrome próprio ou casca de produto; `CHILD` herda a casca do pai; `AUTH` e
`REDIRECT` não participam do chrome autenticado.

| Arquivo | Rota | Bundle | Observação |
|---|---|---|---|
| `pages/index.vue` | `/` | `SHELL` | dashboard inicial |
| `pages/health.vue` | `/health` | `SHELL` | saúde operacional |
| `pages/closing.vue` | `/closing` | `SHELL` | fechamento |
| `pages/syncs.vue` | `/syncs` | `SHELL` | sincronizações |
| `pages/exports.vue` | `/exports` | `SHELL` | exportações |
| `pages/onboarding.vue` | `/onboarding` | `AUTH` | onboarding |
| `pages/communication/index.vue` | `/communication` | `SHELL` | atendimento master-detail via CommunicationWorkspacePage |
| `pages/communication/conversations/[id].vue` | `/communication/conversations/:id` | `SHELL` | deep-link do atendimento via CommunicationWorkspacePage |
| `pages/communication/contacts/index.vue` | `/communication/contacts` | `SHELL` | catálogo de contatos (ShellDataTable) |
| `pages/communication/contacts/[id].vue` | `/communication/contacts/:id` | `SHELL` | ficha do contato (edit/identity/link/export/purge) |
| `pages/communication/quick-responses/index.vue` | `/communication/quick-responses` | `SHELL` | gestão de respostas rápidas (ShellDataTable) |
| `pages/communication/flows/index.vue` | `/communication/flows` | `SHELL` | gestão de fluxos (ShellDataTable) |
| `pages/communication/flows/[id]/index.vue` | `/communication/flows/:id` | `SHELL` | detalhe: metadados, versões, bindings, runs |
| `pages/communication/flows/[id]/editor.vue` | `/communication/flows/:id/editor` | `SHELL` | editor visual Vue Flow (paleta/canvas/inspector) |
| `pages/login.vue` | `/login` | `AUTH` | autenticação |
| `pages/activate.vue` | `/activate` | `AUTH` | ativação |
| `pages/first-access.vue` | `/first-access` | `AUTH` | primeiro acesso |
| `pages/reset-password.vue` | `/reset-password` | `AUTH` | redefinição de senha |
| `pages/two-factor/setup.vue` | `/two-factor/setup` | `REDIRECT` | fluxo autenticado |
| `pages/two-factor-challenge.vue` | `/two-factor-challenge` | `REDIRECT` | fluxo autenticado |
| `pages/clients.vue` | `/clients` | `SHELL` | casca de clientes |
| `pages/clients/index.vue` | `/clients` | `CHILD` | lista de clientes |
| `pages/clients/dashboard.vue` | `/clients/dashboard` | `CHILD` | dashboard de clientes |
| `pages/clients/[id].vue` | `/clients/:id` | `SHELL` | detalhe mestre |
| `pages/clients/[id]/index.vue` | `/clients/:id` | `REDIRECT` | alias |
| `pages/clients/[id]/cadastro.vue` | `/clients/:id/cadastro` | `CHILD` | detalhe |
| `pages/clients/[id]/observacoes.vue` | `/clients/:id/observacoes` | `CHILD` | detalhe |
| `pages/clients/[id]/dados-adicionais.vue` | `/clients/:id/dados-adicionais` | `CHILD` | detalhe |
| `pages/clients/[id]/departamento.vue` | `/clients/:id/departamento` | `CHILD` | detalhe |
| `pages/clients/[id]/contato.vue` | `/clients/:id/contato` | `CHILD` | detalhe |
| `pages/clients/[id]/contratos.vue` | `/clients/:id/contratos` | `CHILD` | detalhe |
| `pages/clients/[id]/configuracao.vue` | `/clients/:id/configuracao` | `REDIRECT` | alias legado |
| `pages/clients/[id]/certificado.vue` | `/clients/:id/certificado` | `REDIRECT` | alias legado |
| `pages/clients/[id]/ccmei.vue` | `/clients/:id/ccmei` | `REDIRECT` | alias legado |
| `pages/clients/[id]/comprovantes.vue` | `/clients/:id/comprovantes` | `REDIRECT` | alias legado |
| `pages/clients/[id]/pagamentos.vue` | `/clients/:id/pagamentos` | `REDIRECT` | alias legado |
| `pages/clients/[id]/sicalc.vue` | `/clients/:id/sicalc` | `REDIRECT` | alias legado |
| `pages/clients/[id]/renuncias.vue` | `/clients/:id/renuncias` | `REDIRECT` | alias legado |
| `pages/clients/[id]/saidas.vue` | `/clients/:id/saidas` | `REDIRECT` | alias legado |
| `pages/clients/[id]/sincronizacao.vue` | `/clients/:id/sincronizacao` | `REDIRECT` | alias legado |
| `pages/clients/[id]/integracoes.vue` | `/clients/:id/integracoes` | `REDIRECT` | alias legado |
| `pages/clients/[id]/fiscal.vue` | `/clients/:id/fiscal` | `REDIRECT` | alias legado |
| `pages/clients/[id]/estabelecimentos.vue` | `/clients/:id/estabelecimentos` | `REDIRECT` | alias legado |
| `pages/monitoring/index.vue` | `/monitoring` | `SHELL` | KontiveHub |
| `pages/monitoring/declarations.vue` | `/monitoring/declarations` | `SHELL` | carteira fiscal |
| `pages/monitoring/sitfis.vue` | `/monitoring/sitfis` | `SHELL` | carteira fiscal |
| `pages/monitoring/installments.vue` | `/monitoring/installments` | `SHELL` | carteira fiscal |
| `pages/monitoring/fgts.vue` | `/monitoring/fgts` | `SHELL` | carteira fiscal |
| `pages/monitoring/guides.vue` | `/monitoring/guides` | `SHELL` | carteira fiscal |
| `pages/monitoring/registrations.vue` | `/monitoring/registrations` | `SHELL` | carteira fiscal |
| `pages/monitoring/tax-processes.vue` | `/monitoring/tax-processes` | `SHELL` | carteira fiscal |
| `pages/monitoring/clients/[clientId].vue` | `/monitoring/clients/:clientId/:section?` | `SHELL` | detalhe fiscal |
| `pages/monitoring/mailbox.vue` | `/monitoring/mailbox` | `SHELL` | casca master-detail |
| `pages/monitoring/mailbox/index.vue` | `/monitoring/mailbox` | `CHILD` | estado vazio |
| `pages/monitoring/mailbox/[id].vue` | `/monitoring/mailbox/:id` | `CHILD` | mensagem |
| `pages/monitoring/mei/index.vue` | `/monitoring/mei` | `SHELL` | carteira MEI |
| `pages/monitoring/simples/index.vue` | `/monitoring/simples` | `SHELL` | carteira Simples |
| `pages/monitoring/simples/[submodule].vue` | `/monitoring/simples/:submodule` | `REDIRECT` | alias legado |
| `pages/monitoring/simples-mei/index.vue` | `/monitoring/simples-mei` | `REDIRECT` | alias legado |
| `pages/monitoring/simples-mei/[submodule].vue` | `/monitoring/simples-mei/:submodule` | `REDIRECT` | alias legado |
| `pages/monitoring/dctfweb/index.vue` | `/monitoring/dctfweb` | `SHELL` | carteira DCTFWeb |
| `pages/monitoring/dctfweb/[submodule].vue` | `/monitoring/dctfweb/:submodule` | `REDIRECT` | alias legado |
| `pages/admin/index.vue` | `/admin` | `REDIRECT` | alias |
| `pages/admin/departments.vue` | `/admin/departments` | `REDIRECT` | alias |
| `pages/admin/fiscal-modules.vue` | `/admin/fiscal-modules` | `SHELL` | administração global |
| `pages/admin/offices/index.vue` | `/admin/offices` | `SHELL` | escritórios |
| `pages/admin/offices/new.vue` | `/admin/offices/new` | `SHELL` | novo escritório |
| `pages/admin/offices/[id].vue` | `/admin/offices/:id` | `SHELL` | detalhe do escritório |
| `pages/admin/owner/index.vue` | `/admin/owner` | `REDIRECT` | alias |
| `pages/admin/serpro.vue` | `/admin/serpro` | `SHELL` | casca SERPRO |
| `pages/admin/serpro/index.vue` | `/admin/serpro` | `CHILD` | console SERPRO |
| `pages/admin/serpro/configuration.vue` | `/admin/serpro/configuration` | `CHILD` | console SERPRO |
| `pages/admin/serpro/catalog.vue` | `/admin/serpro/catalog` | `CHILD` | console SERPRO |
| `pages/admin/serpro/contracts.vue` | `/admin/serpro/contracts` | `CHILD` | console SERPRO |
| `pages/admin/serpro/usage.vue` | `/admin/serpro/usage` | `CHILD` | console SERPRO |
| `pages/admin/serpro/rollout.vue` | `/admin/serpro/rollout` | `CHILD` | console SERPRO |
| `pages/admin/serpro/dte-canary.vue` | `/admin/serpro/dte-canary` | `CHILD` | console SERPRO |
| `pages/settings.vue` | `/settings` | `REDIRECT` | alias legado |
| `pages/settings/index.vue` | `/settings` | `REDIRECT` | alias legado |
| `pages/settings/proxies.vue` | `/settings/proxies` | `REDIRECT` | alias legado |
| `pages/settings/departments.vue` | `/settings/departments` | `REDIRECT` | middleware legado |
| `pages/settings/team.vue` | `/settings/team` | `REDIRECT` | middleware legado |
| `pages/settings/usage.vue` | `/settings/usage` | `REDIRECT` | middleware legado |
| `pages/settings/subscription.vue` | `/settings/subscription` | `REDIRECT` | middleware legado |
| `pages/settings/cte.vue` | `/settings/cte` | `REDIRECT` | alias legado |
| `pages/conta.vue` | `/conta` | `SHELL` | casca da conta |
| `pages/conta/index.vue` | `/conta` | `CHILD` | perfil |
| `pages/conta/escritorio.vue` | `/conta/escritorio` | `CHILD` | escritório |
| `pages/conta/equipe.vue` | `/conta/equipe` | `CHILD` | equipe |
| `pages/conta/departamentos.vue` | `/conta/departamentos` | `CHILD` | departamentos |
| `pages/conta/consumo.vue` | `/conta/consumo` | `CHILD` | consumo |
| `pages/conta/assinatura.vue` | `/conta/assinatura` | `CHILD` | assinatura |
| `pages/docs/index.vue` | `/docs` | `SHELL` | documentos |
| `pages/docs/catalog.vue` | `/docs/catalog` | `SHELL` | catálogo |
| `pages/docs/[accessKey].vue` | `/docs/:accessKey` | `SHELL` | documento |
| `pages/docs/imports/index.vue` | `/docs/imports` | `SHELL` | importações |
| `pages/docs/imports/[id].vue` | `/docs/imports/:id` | `SHELL` | lote de importação |
| `pages/docs/import-batches.vue` | `/docs/import-batches` | `REDIRECT` | alias |
| `pages/notes/index.vue` | `/notes` | `REDIRECT` | alias |
| `pages/notes/[accessKey].vue` | `/notes/:accessKey` | `REDIRECT` | alias |
| `pages/work/index.vue` | `/work` | `SHELL` | visão Tarefas |
| `pages/work/calendar.vue` | `/work/calendar` | `SHELL` | calendário |
| `pages/work/processes/index.vue` | `/work/processes` | `SHELL` | processos em acordeão |
| `pages/work/processes/[id].vue` | `/work/processes/:id` | `SHELL` | detalhe do processo |
| `pages/work/templates/index.vue` | `/work/templates` | `SHELL` | rotinas |
| `pages/work/tasks/index.vue` | `/work/tasks` | `SHELL` | fila de tarefas |
| `pages/work/tasks/[id].vue` | `/work/tasks/:id` | `SHELL` | detalhe da tarefa |
