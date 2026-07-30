## ADDED Requirements

### Requirement: Conteúdo compartilhado é consultável por conversa e contato

O sistema SHALL expor catálogos cursorizados de conteúdo compartilhado por conversa e por contato, com categorias `media`, `links` e `documents`, limite de 1 a 100 e snapshot estável. O escopo do contato SHALL incluir somente conversas canônicas em inboxes visíveis associadas ao contato canônico ou seus doadores.

#### Scenario: Conteúdo de uma conversa
- **WHEN** o ator consulta uma conversa visível
- **THEN** recebe somente itens originados nessa conversa, ordenados do mais recente ao mais antigo

#### Scenario: Conteúdo consolidado do contato
- **WHEN** o ator consulta um contato com conversas em várias inboxes
- **THEN** recebe somente itens das inboxes que pode visualizar, incluindo histórico consolidado pré-merge

#### Scenario: Cursor estável
- **WHEN** o cliente solicita páginas seguintes com o cursor retornado
- **THEN** itens não são duplicados nem omitidos dentro do snapshot da primeira página

### Requirement: Categorias não expõem conteúdo além do necessário

`media` SHALL incluir imagem, áudio, vídeo e sticker; `documents` SHALL incluir os demais attachments; `links` SHALL incluir apenas URLs `http/https` já presentes em `link_preview`. A resposta MUST NOT incluir corpo, ciphertext, JID/LID, `object_id`, `storage_context` ou caminho interno.

#### Scenario: Link estruturado
- **WHEN** uma mensagem possui `link_preview.url` HTTP ou HTTPS válido
- **THEN** a categoria Links apresenta a URL e referência mínima à mensagem de origem sem buscar metadata remotamente

#### Scenario: URL apenas no corpo
- **WHEN** uma URL existe somente no corpo criptografado
- **THEN** ela não é extraída nem retornada pelo catálogo

#### Scenario: Conteúdo sensível
- **WHEN** o catálogo é serializado
- **THEN** apenas campos allowlisted, URLs autenticadas de preview/download e origem navegável são retornados com `private, no-store`

### Requirement: Retenção e autorização permanecem autoridade final

O catálogo SHALL exigir `communication.view`, aplicar tenant e inbox visibility e omitir mensagens ou attachments expurgados, revogados ou view-once. Preview e download MUST continuar sujeitos à autorização vigente e falhar de forma genérica quando o blob estiver indisponível.

#### Scenario: Conteúdo expurgado
- **WHEN** o contato ou attachment foi expurgado
- **THEN** o item não aparece no catálogo e seu stream não pode ser acessado

#### Scenario: Inbox não autorizada
- **WHEN** um contato possui mídia somente em inbox invisível ao ator
- **THEN** a consulta do contato não revela item, contagem ou existência da conversa

### Requirement: Conteúdo compartilhado possui experiência navegável

A SPA SHALL mostrar teaser e vista completa em contato e conversa, com abas Mídias, Links e Documentos. Mídia SHALL usar grid e viewer acessível; documentos e links SHALL usar listas; cada item SHALL permitir download quando aplicável e navegação até sua mensagem de origem.

#### Scenario: Abrir galeria
- **WHEN** o operador aciona “Ver tudo” no rail ou slideover
- **THEN** a área completa abre no mesmo contexto, mantém foco previsível e carrega a categoria selecionada

#### Scenario: Visualizador por teclado
- **WHEN** uma mídia é aberta
- **THEN** o usuário pode navegar anterior/próximo, fechar com `Esc`, baixar e voltar ao gatilho sem perder o contexto

#### Scenario: Ir para mensagem
- **WHEN** o usuário aciona a origem de um item
- **THEN** o workspace abre a conversa, carrega a timeline ancorada e destaca a mensagem correspondente
