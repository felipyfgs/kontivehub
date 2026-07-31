# Matriz de paridade estrutural do template

Inventário canônico do gate estrutural do Nuxt UI Dashboard. `SHELL` exige
chrome próprio ou casca de produto; `CHILD` herda a casca do pai; `AUTH` e
`REDIRECT` não participam do chrome autenticado.

| Arquivo | Rota | Bundle | Observação |
|---|---|---|---|
| `pages/index.vue` | `/` | `SHELL` | dashboard inicial |
| `pages/health.vue` | `/health` | `SHELL` | saúde operacional; rota canônica adicional `/health/type/:type` |
| `pages/closing.vue` | `/closing` | `SHELL` | fechamento |
| `pages/syncs.vue` | `/syncs` | `SHELL` | sincronizações |
| `pages/exports.vue` | `/exports` | `SHELL` | exportações; alias canônico `/exports/new` |
| `pages/onboarding.vue` | `/onboarding` | `AUTH` | onboarding |
| `pages/communication.vue` | `/communication/*` | `SHELL` | outlet persistente; mantém CommunicationWorkspacePage entre lista, conversa e mensagem |
| `pages/communication/index.vue` | `/communication` | `CHILD` | estado de lista do atendimento; herda o workspace persistente |
| `pages/communication/conversations/[id].vue` | `/communication/conversations/:id` | `CHILD` | deep-link do atendimento; herda o workspace persistente |
| `pages/communication/conversations/[id]/messages/[messageId].vue` | `/communication/conversations/:id/messages/:messageId` | `CHILD` | âncora de mensagem; herda o master-detail sem remontá-lo |
| `pages/communication/contacts/index.vue` | `/communication/contacts` | `SHELL` | catálogo Chatwoot-like: cards expansíveis full-width/full-height |
| `pages/communication/contacts/[id].vue` | `/communication/contacts/:id` | `SHELL` | detalhes full-width/full-height com contexto lateral/slideover |
| `pages/communication/contacts/[contactId]/conversations/index.vue` | `/communication/contacts/:contactId/conversations` | `CHILD` | atendimento filtrado; herda o workspace persistente |
| `pages/communication/contacts/[contactId]/conversations/[id].vue` | `/communication/contacts/:contactId/conversations/:id` | `CHILD` | conversa no contexto do contato; herda o workspace persistente |
| `pages/communication/quick-responses/index.vue` | `/communication/quick-responses` | `SHELL` | gestão de respostas rápidas (ShellDataTable) |
| `pages/communication/flows/index.vue` | `/communication/flows` | `SHELL` | gestão de fluxos (ShellDataTable) |
| `pages/communication/flows/[id]/index.vue` | `/communication/flows/:id` | `SHELL` | detalhe: metadados, versões, bindings, runs |
| `pages/communication/flows/[id]/editor.vue` | `/communication/flows/:id/editor` | `SHELL` | editor visual Vue Flow (paleta/canvas/inspector) |
| `pages/login.vue` | `/login` | `AUTH` | autenticação |
| `pages/activate.vue` | `/activate` | `AUTH` | ativação |
| `pages/first-access.vue` | `/first-access` | `AUTH` | primeiro acesso |
| `pages/reset-password.vue` | `/reset-password` | `AUTH` | redefinição de senha |
| `pages/clients.vue` | `/clients` | `SHELL` | casca de clientes |
| `pages/clients/index.vue` | `/clients` | `CHILD` | lista de clientes |
| `pages/clients/dashboard.vue` | `/clients/dashboard` | `CHILD` | dashboard de clientes |
| `pages/clients/[id].vue` | `/clients/:id` | `SHELL` | detalhe mestre |
| `pages/clients/[id]/cadastro.vue` | `/clients/:id/cadastro` | `CHILD` | detalhe; alias anterior `/clients/:id/certificado` redireciona para a rota canônica |
| `pages/clients/[id]/observacoes.vue` | `/clients/:id/observacoes` | `CHILD` | detalhe |
| `pages/clients/[id]/dados-adicionais.vue` | `/clients/:id/dados-adicionais` | `CHILD` | detalhe |
| `pages/clients/[id]/departamento.vue` | `/clients/:id/departamento` | `CHILD` | detalhe |
| `pages/clients/[id]/contato.vue` | `/clients/:id/contato` | `CHILD` | detalhe |
| `pages/clients/[id]/contratos.vue` | `/clients/:id/contratos` | `CHILD` | detalhe |
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
| `pages/monitoring/dctfweb/index.vue` | `/monitoring/dctfweb` | `SHELL` | carteira DCTFWeb |
| `pages/admin/fiscal-modules.vue` | `/admin/fiscal-modules` | `SHELL` | administração global |
| `pages/admin/tenants/index.vue` | `/admin/tenants` | `SHELL` | escritórios |
| `pages/admin/tenants/new.vue` | `/admin/tenants/new` | `SHELL` | novo escritório |
| `pages/admin/tenants/[id].vue` | `/admin/tenants/:id` | `SHELL` | detalhe do escritório |
| `pages/admin/serpro.vue` | `/admin/serpro` | `SHELL` | casca SERPRO |
| `pages/admin/serpro/index.vue` | `/admin/serpro` | `CHILD` | console SERPRO |
| `pages/admin/serpro/configuration.vue` | `/admin/serpro/configuration` | `CHILD` | console SERPRO |
| `pages/admin/serpro/catalog.vue` | `/admin/serpro/catalog` | `CHILD` | console SERPRO |
| `pages/admin/serpro/contracts.vue` | `/admin/serpro/contracts` | `CHILD` | console SERPRO |
| `pages/admin/serpro/usage.vue` | `/admin/serpro/usage` | `CHILD` | console SERPRO |
| `pages/admin/serpro/rollout.vue` | `/admin/serpro/rollout` | `CHILD` | console SERPRO |
| `pages/admin/serpro/dte-canary.vue` | `/admin/serpro/dte-canary` | `CHILD` | console SERPRO |
| `pages/conta.vue` | `/conta` | `SHELL` | casca da conta |
| `pages/conta/index.vue` | `/conta` | `CHILD` | perfil |
| `pages/conta/escritorio.vue` | `/conta/escritorio` | `CHILD` | escritório |
| `pages/conta/equipe.vue` | `/conta/equipe` | `CHILD` | equipe |
| `pages/conta/departamentos.vue` | `/conta/departamentos` | `CHILD` | departamentos |
| `pages/conta/consumo.vue` | `/conta/consumo` | `CHILD` | consumo |
| `pages/conta/assinatura.vue` | `/conta/assinatura` | `CHILD` | assinatura |
| `pages/docs/index.vue` | `/docs` | `SHELL` | documentos |
| `pages/docs/catalog.vue` | `/docs/catalog` | `SHELL` | catálogo |
| `pages/docs/catalog/type/[kind].vue` | `/docs/catalog/type/:kind` | `CHILD` | contexto tipado; herda o catálogo |
| `pages/docs/catalog/client/[clientId].vue` | `/docs/catalog/client/:clientId` | `CHILD` | contexto de cliente; herda o catálogo |
| `pages/docs/[accessKey].vue` | `/docs/:accessKey` | `SHELL` | documento |
| `pages/docs/imports/index.vue` | `/docs/imports` | `SHELL` | importações |
| `pages/docs/imports/new.vue` | `/docs/imports/new` | `SHELL` | criação compartilhável de importação |
| `pages/docs/imports/[id].vue` | `/docs/imports/:id` | `SHELL` | lote de importação |
| `pages/work/index.vue` | `/work` | `SHELL` | visão Tarefas |
| `pages/work/calendar.vue` | `/work/calendar` | `SHELL` | calendário |
| `pages/work/calendar/[view]/[date].vue` | `/work/calendar/:view/:date` | `CHILD` | visão e data estáveis; herda o calendário |
| `pages/work/processes/index.vue` | `/work/processes` | `SHELL` | processos em acordeão |
| `pages/work/processes/[id].vue` | `/work/processes/:id` | `SHELL` | detalhe do processo |
| `pages/work/processes/[id]/[section].vue` | `/work/processes/:id/:section` | `CHILD` | seção tipada tasks/comments/history; herda o detalhe |
| `pages/work/templates/index.vue` | `/work/templates` | `SHELL` | rotinas |
| `pages/work/tasks/index.vue` | `/work/tasks` | `SHELL` | fila de tarefas |
| `pages/work/tasks/[id].vue` | `/work/tasks/:id` | `SHELL` | detalhe da tarefa |
