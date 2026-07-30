## Context

As mudanças arquivadas de contatos já entregaram telefone E.164 seguro, busca por hash, histórico por `contact_id`, rotas separadas e contexto responsivo. A aceitação visual, porém, ainda apontou hierarquia e organização de ações distantes da referência, e o detalhe ainda não expõe conteúdo compartilhado. A conversa já persiste anexos cifrados, renderiza previews por mensagem e usa outbox/Wazync para envio, mas não possui iniciação por contato nem consulta agregada.

A referência visual é o Chatwoot 4.16.2 em `.local/references/chatwoot`, commit `ed5a099425a55af1ab0ea9c5737e8521fabda306`, combinada aos arquétipos locais `customers` e `inbox`. Laravel permanece dono de autorização, tenancy, correlação e egress; Nuxt somente apresenta os contratos públicos; Wazync permanece transporte técnico.

## Goals / Non-Goals

**Goals:**

- Aproximar estrutura, densidade e interação do catálogo/detalhes ao Chatwoot sem abandonar Shell, Nuxt UI ou a identidade do KontiveHub.
- Oferecer iniciação outbound real e segura com texto ou um anexo.
- Expor Mídias, Links e Documentos por conversa e por contato, com navegação até a origem.
- Preservar contratos, tenant, inbox visibility, purge, criptografia e idempotência.

**Non-Goals:**

- Extrair URLs do corpo criptografado, buscar OpenGraph, fazer backfill ou criar tabela de links.
- Enviar múltiplos anexos na primeira mensagem, notas, segments, merge manual ou chamadas.
- Alterar Wazync, seu OpenAPI, branding ou dependências.

## Decisions

### 1. Change aditiva sobre o worktree atual

A implementação integrará os símbolos atuais de contatos e do workspace, sem reverter changes concorrentes. A nova change cria `communication-outbound-conversation-initiation` e `communication-shared-content` e modifica somente requisitos visuais/navegacionais já sincronizados.

### 2. Cards expansíveis e detalhes em duas zonas

O catálogo manterá painel/navbar/paginação e usará toda a largura e altura úteis do body, com coleção rolável, footer fixo no fluxo, cards `rounded-xl`, gap de 16 px e avatar de 42 px. Busca e ações compactas ficam no header no desktop e refluem para uma toolbar somente no mobile. A largura adicional distribui identidade, telefone, vínculo e detalhes sem transformar o conteúdo em gatilho. Cada card oferece uma única navegação para Detalhes, uma única ação de nova conversa e um chevron real para expansão; o formulário inline edita nome e situação, apresenta telefone principal somente leitura, resume identidades/vínculos e concentra apenas cancelar/salvar.

Detalhes manterão rota própria e usarão toda a largura e altura úteis do body. No desktop, perfil e contexto formam duas zonas flexíveis em proporção 3/2, com divisória até o rodapé e scroll independente; abaixo de `lg`, o perfil usa a largura completa e o contexto abre em `USlideover`. A referência Chatwoot define anatomia e comportamento, enquanto cores/tokens permanecem os do KontiveHub.

### 3. Conteúdo compartilhado é uma projeção read-only

Dois endpoints reutilizam mensagens, attachments e `content_encrypted.link_preview`; não haverá migration. `media` inclui imagem, áudio, vídeo e sticker; `documents` inclui os demais anexos; `links` inclui apenas URLs `http/https` estruturadas. Itens são ordenados por `occurred_at DESC` e ID, com cursor opaco e snapshot fixo.

O escopo de conversa exige acesso à inbox. O escopo de contato resolve contato canônico/doadores e intersecta conversas com `visibleInboxIds`. Mensagens/attachments purgados, revogados ou view-once são omitidos. A resposta expõe somente apresentação allowlisted e URLs autenticadas existentes, sempre `private, no-store`.

Um componente Nuxt compartilhado apresenta teaser e vista completa com abas. Mídias usam grade de três colunas e viewer; documentos/links usam listas. “Ir para mensagem” navega com `conversation_id` e `message_id`; a timeline ganha âncora aditiva para carregar e destacar a origem.

### 4. Iniciar conversa é uma operação única e idempotente

`POST /communication/conversations` recebe multipart e `Idempotency-Key`. O ator deve ter `communication.reply` e acesso à inbox; contato e identidade são resolvidos no tenant/canonical class. A ação reutiliza conversa ativa, reabre a resolvida mais recente ou cria `OPEN`.

Um writer transacional compartilhado faz staging do blob, persiste conversa/mensagem/attachment/evento/outbox na mesma transação e agenda dispatch/realtime após commit; falha remove o blob staged. A correlação/locks existentes impedem duas conversas ativas. Replay compara namespace, inbox, identidade/conversa e digest; divergência retorna `409`.

### 5. Rollout outbound é fail-closed em admissão e dispatch

`communication.outbound_conversation.enabled=false`, `kill_switch=true`, `allowed_tenant_ids=[]` e `allow_all_tenants=false`. A admissão e o dispatcher exigem flag ligada, kill switch desligado, tenant autorizado, comunicação/gateway/tenant/inbox operacionais e destino diferente da própria inbox. `/communication/outbound-capabilities` recebe campos aditivos para a SPA esconder/desabilitar o fluxo honestamente.

### 6. Contrato e rollout API-first

OpenAPI e tipos gerados são atualizados no mesmo change. Primeiro publica-se API/configuração ainda desabilitada, depois SPA. Rollback da SPA mantém a API aditiva; rollback da API só ocorre após a SPA. Não há migration nem mudança Wazync.

## Risks / Trade-offs

- [Galeria de contato pode consultar muitas conversas] → usar cursor/snapshot, colunas indexadas atuais e limitar 100 itens; validar plano PostgreSQL sem adicionar schema nesta entrega.
- [Link pode conter segredo em query string] → não logar, não buscar metadata, retornar somente a usuário autorizado e abrir com `noopener noreferrer`.
- [Staging de blob pode ficar órfão em crash entre storage e commit] → limpeza em exceções e monitoramento; coleta genérica de blobs staged fica para evolução separada.
- [Kill switch após aceite não recolhe comando já durável no Wazync] → revalidar antes de criar/enviar outbox e documentar a limitação operacional.
- [Fidelidade conflita com chrome existente] → preservar Shell/tokens e validar lado a lado em quatro viewports, sem copiar código ou branding.

## Migration Plan

1. Publicar endpoints, OpenAPI e configuração com iniciação desabilitada.
2. Publicar SPA com fallback para estados indisponíveis, sem inventar dados.
3. Validar tenant canário antes de liberar `enabled`, retirar kill switch e preencher allowlist.
4. Reativar kill switch para rollback imediato de novas admissões; reverter SPA separadamente se necessário.

## Open Questions

Nenhuma decisão bloqueante permanece.
