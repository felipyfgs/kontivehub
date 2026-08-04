## Why

Mensagens WhatsApp 1:1 já chegam ao Wazync e ao Laravel com conteúdo semântico rico, mas o contrato público e o frontend divergem sobre onde esses dados vivem. Como resultado, contatos, listas de contatos, localizações, enquetes, interativos e parte das mídias podem desaparecer ou ficar inutilizáveis na experiência de atendimento.

Precisamos de uma cobertura ponta a ponta verificável que preserve segurança e tenant, apresente todo conteúdo recebido ou um fallback explícito e só declare sucesso depois de observar mensagens novas no banco, na API e visualmente na interface.

## What Changes

- Classificar todo campo de mensagem da versão fixada do whatsmeow e projetar mensagens 1:1 como conteúdo semântico, cartão seguro, controle estrutural ou `UNSUPPORTED` explícito.
- Desembrulhar filhos de álbuns e wrappers compatíveis, sem criar bolhas fantasmas para marcadores estruturais.
- Evoluir aditivamente o contrato Wazync e a API pública v1 para conteúdo rico, anexos, disponibilidade e cartões somente leitura.
- Renderizar no Nuxt contatos únicos/múltiplos, localizações, enquetes, interativos, cartões raros e fallbacks a partir de `message.content`.
- Reutilizar um viewer autenticado de imagem, vídeo e áudio na timeline e no conteúdo compartilhado, com navegação e acessibilidade.
- Adicionar importação idempotente e autorizada de um contato compartilhado a partir da mensagem.
- Suportar `HEAD` e byte ranges nos streams privados de áudio e vídeo.
- Adicionar um gate de observação real pós-implementação, correlacionando somente mensagens novas entre Wazync, Laravel, API e UI.
- Transportar eventos e comandos WhatsApp nos dois sentidos pelo NATS JetStream e permitir armazenamento cifrado de mídia em backend local ou MinIO/S3.
- Não executar replay, backfill ou recuperação automática das mídias históricas ausentes.

## Capabilities

### New Capabilities

- `whatsapp-message-projection`: classificação e projeção sem perdas de mensagens recebidas em chats WhatsApp 1:1.
- `communication-message-presentation`: contrato público e experiência visual para conteúdo rico, anexos e viewer de mídia.
- `shared-contact-import`: apresentação e importação autorizada/idempotente de contatos compartilhados.
- `live-message-observation`: gate de aceitação com watermark e correlação de mensagens novas no banco, API, tempo real e UI.
- `durable-communication-infrastructure`: transporte durável via JetStream e armazenamento privado compatível com S3/MinIO.

### Modified Capabilities

Nenhuma; o repositório não possuía specs OpenSpec antes desta mudança.

## Impact

- `apps/wazync`: catálogo, normalização, wrappers, publicação JetStream e métricas de mensagens.
- `apps/api`: consumo JetStream, contrato do gateway, projeção, resources, armazenamento MinIO, streaming, endpoint de contato e OpenAPI v1.
- `apps/web`: tipos, summaries, bolhas de mensagem, viewer reutilizável, API client e testes visuais.
- Contratos gerados e testes de compatibilidade entre Go, PHP e TypeScript.
- Docker/Swarm: NATS JetStream, MinIO privado, buckets e processos consumidores; sem migração de banco ou alteração retroativa dos dados históricos.
