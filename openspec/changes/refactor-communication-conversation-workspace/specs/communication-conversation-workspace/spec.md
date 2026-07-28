## ADDED Requirements

### Requirement: Listagem expõe contrato aditivo e filtro unread
`GET /api/v1/communication/conversations` SHALL aceitar `unread` e adicionar `display_name`, `display_name_source`, `unread_count` e `read_state.version/last_read_through_message_id`, preservando campos legados durante o rollout.

Cada linha SHALL incluir contexto empresarial secundário e preview semântico por tipo. Logs SHALL NOT registrar corpo de mensagem.

#### Scenario: Filtro Todas
- **WHEN** `unread` não é informado ou é falso
- **THEN** conversas autorizadas lidas e não lidas podem ser retornadas

#### Scenario: Filtro Não lidas
- **WHEN** `unread=true`
- **THEN** somente conversas canônicas com pelo menos uma linha no ledger são retornadas

#### Scenario: Preview de mídia
- **WHEN** a última mensagem é imagem, áudio, vídeo, documento, localização, contato ou revogada
- **THEN** a lista recebe um preview textual sem card/fetch remoto de imagem

### Requirement: Detalhe legado e timeline cursorizada coexistem
`GET /conversations/{id}` SHALL preservar mensagens por padrão e aceitar `include_messages=false`. `GET /conversations/{id}/messages` SHALL usar cursor opaco keyset sobre `(occurred_at,id)`, limite 1..100 e `anchor=latest|first_unread`.

A resposta SHALL manter ordem cronológica e fornecer `older_cursor`, `newer_cursor`, `first_unread_message_id`, `snapshot_through_message_id` e `read_state_version`.

#### Scenario: Âncora latest
- **WHEN** não há cursor e `anchor=latest`
- **THEN** a página contém as mensagens mais recentes em ordem cronológica

#### Scenario: Âncora first_unread
- **WHEN** existe pendência e `anchor=first_unread`
- **THEN** a página inclui a primeira mensagem pendente e fornece cursores para ambos os sentidos quando aplicável

#### Scenario: Timestamps iguais
- **WHEN** mensagens compartilham `occurred_at`
- **THEN** o desempate por `id` evita duplicação e lacunas entre páginas

#### Scenario: Detalhe sem timeline
- **WHEN** `include_messages=false`
- **THEN** metadados da conversa são retornados sem carregar a coleção integral

### Requirement: Workspace mantém master–detail e estados reais
A SPA SHALL preservar painel redimensionável, timeline adjacente, contexto largo e `USlideover` mobile, além de deep-link, URL↔seleção, setas, scroll, restauração de foco e `Esc`.

A lista SHALL mostrar nome resolvido, contexto secundário, preview, horário e contador discreto; apenas linhas não lidas usam tipografia destacada. Avatar SHALL usar iniciais locais, sem fetch remoto.

#### Scenario: Estados de lista
- **WHEN** a listagem está carregando, falha, fica vazia ou tem próxima página
- **THEN** o workspace mostra o estado real correspondente sem dados sintéticos

#### Scenario: Conversa pinada no filtro unread
- **WHEN** a conversa selecionada fica lida sob o filtro “Não lidas”
- **THEN** ela permanece pinada até fechar ou trocar de seleção

### Requirement: Leitura ocorre somente após consumo bem-sucedido
Abrir uma conversa SHALL marcar READ somente após a timeline renderizar com sucesso. A primeira não lida SHALL ser capturada como âncora/divisor persistente da sessão aberta.

Inbound nova com a conversa aberta SHALL ser marcada automaticamente somente se `document.visibilityState=visible` e a timeline estiver no final; caso contrário o contador e o controle de novas mensagens SHALL permanecer.

#### Scenario: Render falha
- **WHEN** carregar ou renderizar a timeline falha
- **THEN** a SPA não envia READ

#### Scenario: Documento oculto
- **WHEN** chega inbound enquanto o documento está oculto
- **THEN** ela permanece não lida

#### Scenario: Usuário está lendo histórico
- **WHEN** chega inbound e a timeline não está no final
- **THEN** a pendência permanece e o controle de novas mensagens é exibido

#### Scenario: Conflito UNREAD
- **WHEN** a API retorna `409 READ_STATE_VERSION_CONFLICT`
- **THEN** a SPA recarrega o estado autoritativo e não inventa contagem local

### Requirement: Frontend não registra conteúdo de mensagem
Nenhum `console.log`, métrica ou erro do workspace SHALL incluir corpo de mensagem ou payload bruto do composer.

#### Scenario: Envio e automação
- **WHEN** uma mensagem humana ou payload de automação é processado na SPA
- **THEN** qualquer log contém no máximo IDs internos e códigos, nunca `body` ou conteúdo
