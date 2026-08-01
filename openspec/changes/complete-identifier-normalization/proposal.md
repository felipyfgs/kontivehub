## Why

A primeira normalização removeu muitas redundâncias, mas deixou famílias inteiras de identificadores que ainda repetem seus namespaces ou diretórios e casing inconsistente como `Pagtoweb`. A continuação fecha esse inventário e adiciona gates para impedir a reintrodução desses padrões.

## What Changes

- Remover prefixos redundantes de Requests, Actions, Controllers, Resources, Enums e Jobs já isolados por namespace na API.
- Padronizar `PagtoWeb` nos Models e identificadores internos, preservando explicitamente as tabelas `pagtoweb_*`.
- Eliminar nomes duplicados produzidos pelo auto-import de componentes Nuxt e modularizar os tipos de Communication antes de encurtá-los.
- Tornar nomes internos e de configuração do Wazync explícitos para WhatsApp, origem de mídia e ingestão de eventos.
- Adicionar inventário de renomes e verificações arquiteturais contra repetição contextual e casing obsoleto.
- Remover adaptadores, aliases, fallbacks, nomes de arquivos e terminologia de compatibilidade anterior; apenas entradas canônicas continuam aceitas.
- **BREAKING**: trocar FQCNs de Jobs e Models sem aliases; o rollout exige filas drenadas e deploy coordenado.
- **BREAKING**: substituir `WAZYNC_WA_*` por `WAZYNC_WHATSAPP_*` em todas as instâncias no mesmo rollout.
- **BREAKING**: remover queries de navegador anteriores, aliases `WAZYNC_EVENTS_URL`/`WAZYNC_MEDIA_URL` e o fallback de foto sem estado explícito.

## Capabilities

### New Capabilities

- `identifier-naming-integrity`: Define nomes contextuais, casing canônico, verificações residuais e invariantes de compatibilidade observável para refatorações de identificadores.

### Modified Capabilities

Nenhuma capability existente muda comportamento de produto.

## Impact

- API Laravel: namespaces de Communication, Tenant e Work; filas Horizon; Models e projeções PagtoWeb; artefatos de inventário/OpenAPI.
- Web Nuxt: componentes auto-importados, composables, tipos de Communication, testes textuais e artefatos gerados.
- Wazync: configuração, worker de mídia e nomes locais de transporte, além dos exemplos operacionais versionados.
- Contratos públicos e privados preservam URIs, JSON, `operationId`, status, schemas, HMAC, métricas e valores serializados de domínio; apenas FQCNs internos e nomes de configuração operacional sofrem quebra coordenada.
