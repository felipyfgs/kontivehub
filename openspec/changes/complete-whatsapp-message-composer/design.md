## Context

O composer atual concentra texto, nota interna, uma resposta rápida, um único arquivo, upload de sticker WebP, um popover curto de emojis e gravação simples. Seu `ComposerPayload` restringe `kind` a `TEXT | IMAGE | AUDIO | VIDEO | DOCUMENT | STICKER`, embora `SendMessageRequest`, `CreateMessageAction` e o catálogo outbound já aceitem localização, contato, enquete e interativo. O Web também não consulta `outbound-capabilities` no composer, portanto o controle visual não representa o contrato efetivo da inbox.

As capturas reais do WhatsApp Web fornecidas em 2026-08-04 mostram três padrões relevantes: um único botão “+” abre um menu por família; emoji, GIF e figurinha vivem em um picker pesquisável com abas; e a gravação substitui o campo por uma superfície temporal com descarte, pausa/reprodução e envio. Chatwoot reforça os padrões de upload múltiplo, preview e recorder com waveform, mas não é fonte de domínio nem deve ser copiado literalmente.

A crítica Impeccable de 2026-08-04 classificou a proposta inicial como tecnicamente sólida, porém genérica como superfície operacional. O design deve seguir `DESIGN.md` e os arquétipos do dashboard: Public Sans, superfícies zinc, Verde Kontive reservado a ação/seleção/estado, hierarquia scan-first, primitivos Nuxt UI e transformação responsiva. WhatsApp e Chatwoot são referências de comportamento, não autoridades visuais.

Laravel permanece dono de autorização, tenant, validação, idempotência, armazenamento e contrato público. Nuxt nunca chama Wazync ou um provedor de GIF diretamente. Wazync aceita apenas DTOs allowlisted e constrói protobufs whatsmeow internamente. O trabalho deve coexistir com `complete-whatsapp-message-pipeline`, inclusive fila JetStream e mídia privada local/MinIO, sem considerar seus três gates de observação pendentes como concluídos.

## Goals / Non-Goals

**Goals:**

- Oferecer no composer todas as famílias que a inbox realmente consegue enviar, com interação equivalente nos viewports desktop e mobile.
- Unificar texto, conteúdo estruturado, mídia, câmera, sticker, GIF e voz em um modelo de rascunho tipado e testável.
- Fechar os gaps de contrato para contatos múltiplos, lote de mídia, GIF, PTV, evento e view-once sem expor protobuf ou URL privada.
- Preservar autorização, idempotência, citação, nota interna, respostas rápidas, status monotônico e recuperação após erro.
- Manter cliente/conversa, inbox e destino visíveis o suficiente para prevenir envio no contexto errado sem expor dados além do necessário.
- Reduzir escolhas simultâneas, usar progressive disclosure e oferecer estados/copy acionáveis em português do Brasil.
- Provar cada família com mensagens reais e correlação técnica/visual pós-deploy.

**Non-Goals:**

- Copiar aparência, assets proprietários, dados de emoji/GIF ou código do WhatsApp/Chatwoot.
- Criar editor de produtos, pedidos, pagamentos ou outras ações financeiras; rich cards comerciais continuam somente leitura.
- Enviar grupos, Status ou newsletters, ou remover os gates de rollout de conversa outbound.
- Tornar obrigatório um provedor comercial de GIF; instalações sem provedor continuam operacionais e informam indisponibilidade.
- Migrar ou recuperar mídia histórica e reprocessar mensagens anteriores ao deploy.

## Decisions

### 1. O composer será capability-driven por inbox

O workspace carregará `outbound-capabilities` junto do catálogo e derivará uma visão efetiva combinando permissão do usuário, estado da inbox, limites de MIME/tamanho e flags por família/variante. O launcher renderizará somente opções conhecidas; opções reconhecidas mas indisponíveis poderão permanecer desabilitadas com motivo estável quando isso ajudar o operador a entender o rollout.

O catálogo evoluirá de `Record<string, unknown>` para DTOs discriminados e documentados, incluindo `multiple`, `max_items`, `caption`, `camera`, `gif`, `ptv`, `view_once`, `provider_search`, `modes` e códigos de indisponibilidade. A API continuará sendo a fonte de verdade; o Nuxt não inferirá suporte pelo MIME nem pela presença de um componente.

Alternativa rejeitada: sempre mostrar todos os botões e deixar o POST falhar. Isso produz uma promessa falsa de suporte e degrada autorização/fail-closed.

### 2. Um rascunho discriminado substituirá `body + file + fileKind`

O Web introduzirá um `ComposerDraft` discriminado com famílias `TEXT`, `MEDIA_BATCH`, `AUDIO`, `STICKER`, `LOCATION`, `CONTACTS`, `POLL`, `EVENT` e `INTERACTIVE`. Campos comuns — conversa, texto/legenda, citação, idempotency key, nota interna e estado de envio — serão separados dos campos exclusivos da família. Cada modal produzirá um draft válido; somente o adaptador de API transformará esse draft em JSON ou `FormData`.

Rascunhos permanecerão isolados por `conversationId`, serão limpos apenas após ACK da criação local e preservarão dados editáveis em 4xx/5xx. Texto e conteúdo estruturado podem permanecer no store da sessão, mas arquivos, blobs e object URLs não serão gravados em `localStorage` nem em outro armazenamento persistente do browser. Eles sobrevivem a trocas internas de conversa enquanto a SPA está viva; fechamento/reload ou descarte do processo pode perdê-los e será avisado antes de uma navegação controlável. Object URLs, streams de câmera e microfone serão liberados ao remover mídia, invalidar o draft ou desmontar definitivamente o composer. Nota interna continuará aceitando somente texto e terá draft separado do WhatsApp.

Alternativa rejeitada: acrescentar refs opcionais ao `Composer.vue`. A combinação de flags permitiria estados impossíveis, como enquete com arquivo ou PTT com localização.

### 3. A superfície seguirá tarefas operacionais e famílias do WhatsApp, não MIME genérico

O botão “+” abrirá `ComposerAttachmentMenu` com um primeiro nível estável de no máximo quatro grupos: **Arquivos e mídia** (Documento, Fotos e vídeos, Câmera, Áudio), **Cliente e contexto** (Contatos, Localização), **Criar** (Enquete, Evento, Nova figurinha) e **Mais** (interativos de negócio e futuras famílias avançadas). Cada camada terá no máximo quatro decisões visíveis; capabilities removem ou explicam indisponibilidade sem reordenar os grupos, preservando memória muscular. Desktop usará popover/submenu ancorado; mobile usará bottom sheet em etapas com a mesma ordem semântica, título e caminho de retorno.

Uma linha compacta de contexto permanecerá associada ao composer e ao preview, mostrando identidade da conversa/cliente e inbox; o destino será exibido apenas no grau autorizado e necessário, com telefone mascarado quando aplicável. Trocar conversa, inbox ou tenant revalida o draft e nunca reaproveita mídia ou conteúdo estruturado em outro destino. Documento sensível e view-once apresentam confirmação do contexto e da consequência de privacidade antes da submissão.

`ComposerMediaPreview` mostrará thumbnails, nome, MIME, tamanho, ordem, legenda, variante e remoção antes do envio. Câmera usará `getUserMedia` e produzirá um `File` normal; ausência de permissão oferece fallback para seleção de arquivo. Nova figurinha recorta/redimensiona uma imagem no browser e só produz WebP dentro dos limites anunciados.

### 4. Emoji, GIF e sticker compartilharão um picker extensível

`ComposerExpressionPicker` terá busca, recentes e navegação por teclado. Emoji usa dados Unicode versionados, substitui a seleção ativa ou insere em `selectionStart/selectionEnd` e devolve o foco ao editor. Sticker lista primeiro itens locais/recentes e também permite criar/enviar WebP. GIF permite upload local; busca remota aparece somente quando Laravel anuncia um provider configurado.

Quando habilitada, a busca de GIF será feita por uma porta Laravel tenant-aware com timeout, rate limit, cache curto e resposta allowlisted. O Web nunca receberá credenciais nem chamará o provedor diretamente. A seleção será materializada como upload controlado ou referência temporária recuperada e validada pelo Laravel antes da criação da mensagem.

Alternativa rejeitada: integrar diretamente Tenor/Giphy no Nuxt. Isso exporia chaves, IP do operador e disponibilidade de terceiro fora do domínio.

### 5. A gravação de voz terá uma máquina de estados explícita

`ComposerVoiceRecorder` adotará `idle → recording ↔ paused → preview → sending`, com `error` recuperável. Durante recording exibe duração, indicador/waveform, descarte, pausa e envio; em preview permite reprodução, retomada ou descarte. Duração e estado serão anunciados textualmente e não dependerão somente da waveform ou de cor. O blob real determina MIME/extensão, e `ptt=true` só é enviado para áudio compatível. Limite de tempo e bytes vem das capabilities, não de constante duplicada.

Waveform será calculado localmente a partir de amostras limitadas, sem upload antecipado. Todos os tracks e object URLs serão encerrados em transições terminais.

### 6. Contratos simples continuam; lotes recebem endpoint próprio

`POST /api/v1/communication/conversations/{conversation}/messages` continuará retornando uma única `Message` e ganhará suporte documentado a `contacts[]`, `event`, `gif`, `ptv` e `view_once`, mantendo `contact` e payloads atuais por compatibilidade.

Múltiplas fotos/vídeos/documentos usarão `POST .../message-batches`, com `client_batch_id`, lista ordenada de itens, legenda/variante por item e uma idempotency key por lote. Laravel valida e armazena o lote inteiro antes de criar mensagens/outbox em uma transação; após aceitação local, cada filho possui status próprio. A resposta contém o batch e as mensagens criadas, sem alterar o shape do endpoint singular.

Wazync receberá um comando tipado por mensagem ou um envelope de lote explicitamente versionado conforme a API do whatsmeow permitir. Se `albumMessage` não puder ser produzido de modo interoperável na versão fixada, o capability `album_native` ficará falso e o lote será enviado como sequência ordenada correlacionada, sem alegar álbum nativo.

### 7. Variantes outbound serão explícitas e allowlisted

- GIF é vídeo compatível com `gifPlayback`, nunca protobuf arbitrário.
- PTV é vídeo compatível com flag circular/PTV e limites próprios.
- View-once é permitido somente para imagem/vídeo e deixa metadata de privacidade explícita na projeção local.
- Contatos múltiplos usam `contactsArrayMessage`; contato singular mantém `contactMessage`.
- Evento usa DTO limitado a título, descrição, início/fim, timezone, local e campos de participação suportados; sua projeção continua cartão seguro.
- Sticker aceita apenas WebP validado; criação visual não amplia MIME do gateway.

Essas variantes serão testadas contra o descriptor whatsmeow fixado. Uma variante indisponível não cairá silenciosamente para outra sem informar o usuário.

### 8. Status e operações não podem regredir ou ficar sem projeção

A aceitação do outbox usará a ordenação de `MessageStatus` em vez de sobrescrever `DELIVERED/READ/PLAYED` com `ACCEPTED`. Edição, reação e revogação deverão produzir ou correlacionar um evento terminal projetável no Laravel; o composer/timeline exibirá estado pendente limitado, removido após confirmação ou falha.

Essa decisão incorpora os achados ao vivo de 2026-08-04: uma reconciliação regrediu uma mensagem entregue para aceita, e comandos remotos processados não atualizaram o conteúdo local. A nova superfície não será considerada pronta enquanto esses bloqueios afetarem feedback do operador.

### 9. Aceitação combina contrato, browser e mensagem real

Testes unitários cobrirão reducers de draft, validação, serialização, foco, recorder e cleanup. Testes Laravel/Go cobrirão DTOs, tenant, idempotência, mídia, builders e status. Playwright cobrirá desktop/mobile, menus, modais, preview, teclado, câmera/microfone simulados e erro.

O gate final usará uma conversa real pós-watermark e correlacionará IDs de mensagem/batch, comando JetStream, projeção, API e `[data-message-id]`. Tipos não produzidos ao vivo serão classificados como fixture apenas; opções desabilitadas não contarão como sucesso.

### 10. O contrato visual e de feedback será operacional e mensurável

Cada superfície usará primitivos Nuxt UI e tokens de `DESIGN.md`: containers neutros, bordas/rings antes de sombra, uma única ação primária verde por estado e sinais semânticos nunca comunicados apenas por cor. A ordem visual será contexto, estado/draft, conteúdo e ação; o composer não cria um shell paralelo ao `UDashboardPanel` nem copia o tema do WhatsApp.

O draft e cada filho de lote terão uma projeção visual da máquina `idle → validating → uploading → queued → sent → delivered/read`, com `blocked`, `failed`, `cancelled` e `partially_sent` quando aplicáveis. Upload exibe progresso por item; falha apresenta causa em linguagem operacional, impacto e próxima ação idempotente. `aria-live` anuncia somente transições relevantes sem repetir recibos ruidosos. Um batch parcialmente entregue nunca volta a parecer um único envio pendente nem oferece retry dos filhos já aceitos.

Menus, dialogs, sheets e recorder terão alvo mínimo de 44×44 CSS pixels no mobile, focus trap quando modal, restauração ao acionador, Escape/Voltar previsíveis, safe-area, teclado virtual, zoom de 200% sem perda de função e `prefers-reduced-motion`. A grade visual de emoji pode exceder quatro itens porque é uma superfície de reconhecimento, mas busca, categorias e recentes evitam memória e varredura desnecessária.

## Risks / Trade-offs

- [Escopo amplo demais para uma entrega] → Implementar por slices capability-gated: famílias já suportadas, UX de mídia/voz, depois novos builders.
- [APIs Web de câmera/microfone variam entre navegadores] → Detectar suporte, testar MIME real e oferecer fallback de arquivo sem quebrar o draft.
- [GIF remoto introduz privacidade, custo e licenciamento] → Porta Laravel opcional, fail-closed e decisão de provider antes do rollout.
- [Lote parcialmente entregue no WhatsApp] → Atomicidade termina na criação local; apresentar status individual e retry idempotente por filho.
- [View-once pode não ser recuperável nem verificável no viewer] → Aviso explícito antes do envio e placeholder de privacidade depois, sem tentativa de replay.
- [Event/album/PTV variam por versão do protocolo] → Capability derivada de builders e testes da versão whatsmeow fixada; sem fallback enganoso.
- [Rascunhos com arquivos consomem memória] → Limites de quantidade/bytes, object URL cleanup e ausência de persistência binária em localStorage.
- [O sistema operacional encerra a aba mobile e perde blobs em memória] → Avisar que anexos ainda não enviados duram somente na sessão viva, preservar texto/estrutura quando seguro e nunca prometer recuperação binária inexistente.
- [Agrupamento adiciona um passo para power users] → Ordem fixa, navegação por teclado e atalhos/contexto recente sem reordenação adaptativa do menu.
- [Mudanças concorrentes no timeline/resource] → Implementação deve preservar o worktree existente e integrar após rebase/review, sem sobrescrever correções alheias.

## Migration Plan

1. Corrigir status monotônico e projeção terminal de ações; manter novas capabilities falsas.
2. Publicar contratos Laravel/OpenAPI/Wazync aditivos e testes, sem expor novos controles.
3. Entregar o novo draft e composer para texto e famílias já suportadas: mídia singular, voz, localização, contato, enquete e interativo.
4. Ativar lote, contatos múltiplos, GIF/PTV, evento, view-once, câmera e criação de sticker somente após builder e capability correspondente passarem nos três serviços.
5. Executar gates completos e observação real desktop/mobile por tipo antes de allowlist de tenant.
6. Rollback desativa capabilities e restaura o composer anterior; endpoints aditivos e mensagens já criadas permanecem legíveis. Não há backfill nem migração destrutiva.

## Open Questions

- Qual provedor de GIF, termos de uso, política de conteúdo e orçamento serão aprovados? Até a decisão, somente upload local será habilitável.
- A versão whatsmeow fixada permite álbum nativo e PTV interoperáveis ou o produto deve declarar apenas lote ordenado e vídeo comum?
- Quais campos de evento são efetivamente aceitos em chats 1:1 pelo dispositivo conectado e quais devem permanecer fixture-only?
- O limite inicial de lote será 10 ou outro valor menor derivado de testes reais de memória, upload e rate limiting?
