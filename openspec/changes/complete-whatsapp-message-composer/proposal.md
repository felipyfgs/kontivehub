## Why

O pipeline já aceita várias famílias outbound, mas o composer do Nuxt só produz texto, um arquivo, sticker WebP e PTT; localização, contato, enquete e interativos existem na API sem uma forma de criação na interface. Além disso, famílias presentes no WhatsApp Web — lote de fotos e vídeos, GIF, evento, criação de figurinha, variações de privacidade e uma gravação de voz completa — ainda não possuem contrato ponta a ponta, deixando capacidades reais inacessíveis ou inconsistentes para o operador.

## What Changes

- Substituir os controles isolados do composer por uma experiência que absorve padrões funcionais do WhatsApp Web sem copiar sua aparência: botão único de anexos, picker pesquisável de emoji/GIF/figurinhas e modos explícitos para texto, conteúdo estruturado, pré-visualização e gravação, todos no design system do KontiveHub.
- Organizar o launcher por tarefas estáveis em camadas de no máximo quatro escolhas — arquivos e mídia, cliente e contexto, criação e ações avançadas — preservando reconhecimento, teclado e a mesma ordem semântica no desktop e mobile.
- Tornar o estado do rascunho tipado por família e permitir criar texto/link, imagem e vídeo, câmera, áudio/PTT, documento, sticker, localização, contato único ou múltiplo, enquete, evento e interativo quando a inbox anunciar suporte.
- Manter cliente/conversa, inbox e destinatário de forma compacta e inequívoca durante a composição; revalidar esse contexto ao trocar de conversa e confirmar variantes sensíveis como view-once antes do envio.
- Adicionar pré-visualização antes do envio, legenda, ordenação e remoção de mídia, validação de tamanho/MIME, preservação de citação e rascunho por conversa e feedback individual de validação, upload, fila, envio e falha recuperável.
- Evoluir a gravação de voz para uma máquina de estados com duração, waveform, pausa, retomada, reprodução, descarte e envio direto, sempre encerrando tracks e URLs temporárias.
- Evoluir aditivamente Laravel, OpenAPI e Wazync para os gaps reais de outbound: múltiplos contatos, lotes/álbuns de mídia, GIF, vídeo circular, evento e view-once para mídia compatível; câmera e criação de sticker continuam transformações locais sobre contratos allowlisted.
- Tornar GIF remoto opcional e fail-closed: busca somente por um provedor configurado no Laravel, sem o navegador chamar terceiros diretamente; upload local continua disponível quando suportado.
- Fazer o composer consumir capabilities efetivas por inbox/conversa e ocultar ou desabilitar cada opção com motivo estável, sem anunciar suporte apenas porque existe um botão.
- Definir copy operacional em pt-BR e critérios verificáveis de foco, anúncios acessíveis, alvo de toque, zoom, safe-area, teclado virtual e interrupção mobile; binários permanecem somente na sessão viva e nunca em `localStorage`.
- Preservar nota interna, respostas rápidas, citação, autorização, isolamento por tenant, idempotência e o fluxo Nuxt → Laravel → JetStream → Wazync.
- Exigir aceitação real por tipo no banco, API, JetStream e DOM desktop/mobile, incluindo falha segura, retry e ausência de duplicação.

## Capabilities

### New Capabilities

- `whatsapp-message-composition`: experiência completa e responsiva do composer, estados de rascunho, menus, pickers, modais, previews, gravação e capability gating.
- `whatsapp-outbound-message-families`: contratos aditivos do Laravel e builders tipados do Wazync para todas as famílias e variações outbound expostas pelo composer.
- `whatsapp-composer-live-acceptance`: verificação ponta a ponta de que cada ação do composer cria, envia, projeta e apresenta a mensagem correta sem perda ou alegação de suporte não observado.

### Modified Capabilities

Nenhuma capability principal existente está registrada em `openspec/specs/`; esta mudança coordena-se com `complete-whatsapp-message-pipeline` sem substituir seus requisitos de projeção, mídia privada e fila durável.

## Impact

- `apps/web`: `Composer.vue`, workspace/composable de comunicação, cliente API, tipos, novos componentes de menu/picker/modal/preview/recorder e testes Vitest/Playwright.
- `apps/api`: requests/DTOs/actions/resources, catálogo de outbound capabilities, endpoints estruturados e em lote, OpenAPI, autorização, validação, armazenamento temporário e testes de tenant/idempotência.
- `apps/wazync`: DTO `MESSAGE_SEND`, validação, builders whatsmeow para contatos múltiplos, mídia em lote/álbum, GIF/PTV, evento e view-once, além de testes reflexivos e integrados.
- Infraestrutura opcional para busca de GIF deve ser configurada no Laravel, privada por tenant e desativada por padrão; nenhuma credencial ou URL de terceiro chega ao Web.
- Não há remoção imediata de campos ou endpoints v1: o payload simples atual continua compatível enquanto as novas famílias são adicionadas.
