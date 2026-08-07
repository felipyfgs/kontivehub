## Why

O composer já envia figurinhas, mas não reaproveita as figurinhas recentes e favoritas que o dispositivo pareado entrega parcialmente pelo whatsmeow. Sem uma biblioteca própria, operadores precisam reenviar ou importar o mesmo WebP manualmente, enquanto a interface pode sugerir uma sincronização completa que o protocolo não garante.

## What Changes

- Capturar figurinhas recentes fornecidas por `HistorySync.RecentStickers` e mudanças de favorito fornecidas pelo índice allowlisted `favoriteSticker` do App State.
- Criar uma biblioteca privada de figurinhas por tenant e inbox, com proveniência, deduplicação por hash, estado recente/favorito e limites operacionais.
- Baixar mídia somente quando o whatsmeow fornecer metadados criptográficos suficientes; manter itens incompletos indisponíveis com motivo estável.
- Expor endpoints Laravel tenant-aware para listar, favoritar, remover da biblioteca e importar WebP do dispositivo, sem entregar caminhos, chaves ou URLs do WhatsApp ao navegador.
- Integrar a biblioteca ao picker do composer com abas/segmentos “Recentes”, “Favoritas” e importação local, preservando o envio atual de stickers.
- Sincronizar mudanças observadas sem afirmar equivalência total com a coleção do WhatsApp; ausência de bootstrap, expiração de mídia e remoções não observadas permanecem explícitas.
- Adicionar retenção, quotas, autorização, auditoria e limpeza de objetos privados sem referência.

## Capabilities

### New Capabilities

- `whatsapp-sticker-library`: captura parcial de recentes/favoritas do dispositivo pareado, armazenamento privado, catálogo tenant-aware, importação WebP e seleção segura no composer.

### Modified Capabilities

Nenhuma capability principal está registrada em `openspec/specs/`; a mudança integra-se aditivamente ao composer e ao pipeline existentes.

## Impact

- `apps/wazync`: projeção allowlisted de `HistorySync.RecentStickers` e `favoriteSticker`, download limitado e eventos JetStream idempotentes.
- `apps/api`: modelo/migration, armazenamento privado, consumo de eventos, autorização, endpoints/resources, quotas, retenção e testes multi-tenant.
- `apps/web`: cliente/tipos, picker de expressões, estados de sincronização/indisponibilidade e testes Vitest/Playwright.
- Contratos OpenAPI, documentação operacional e observabilidade ganham a classificação “sincronização parcial”; não há API oficial para listar toda a coleção de favoritos ou packs da conta.
