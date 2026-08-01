## Context

O change `normalize-codebase-identifiers` removeu a primeira camada de redundância, mas Requests e Controllers Laravel, nomes gerados pelo auto-import Nuxt e identificadores do Wazync ainda repetem o contexto já expresso pelo caminho. A limpeza também alcança FQCNs persistidos em filas e Models serializados, portanto requer rollout coordenado mesmo sem alterar contratos HTTP ou dados.

## Goals / Non-Goals

**Goals:**

- Fazer basenames e símbolos expressarem apenas a responsabilidade não informada pelo namespace ou diretório.
- Padronizar `PagtoWeb` e `WhatsApp` nos identificadores internos.
- Atualizar todos os consumidores, testes e artefatos legítimos no mesmo lote por app.
- Criar gates que detectem as famílias redundantes conhecidas e casing obsoleto.
- Preservar os contratos observáveis e as tabelas existentes.

**Non-Goals:**

- Alterar URI, JSON, `operationId`, status, schema, HMAC, métricas, backing values ou nomes PostgreSQL.
- Abreviar termos oficiais como SERPRO, PGDAS-D, DCTFWeb, AutXML, JID, MIME e WhatsMeow.
- Dividir `event_bridge.go` ou `postgres.go`, redesenhar UI ou mudar comportamento de produto.
- Executar drenagem de filas, deploy, push ou comandos de produção.

## Decisions

### Contexto sai do basename somente quando permanece inequívoco

Requests, Actions, Controllers, Resources, Enums e Jobs perdem `Communication`, `Tenant` ou `Work` quando o namespace já contém o domínio. Models, Policies e Commands no namespace raiz mantêm qualificadores de domínio. O inventário em `inventory.md` é a allowlist de decisões, evitando regras cegas por comprimento.

### Requests compartilhados recebem nomes pela responsabilidade

As bases de Communication e Work passam a `TenantScopedRequest`, pois sua função é rejeitar `tenant_id` arbitrário; o hook passa a `prepareScopedValidation`. Bases Tenant perdem apenas o prefixo contextual e mantêm o conceito protegido, como `SettingsRequest`, `MemberRequest` e `AutXmlRequest`.

### Models PagtoWeb preservam o schema por declaração explícita

Os seis Models passam de `Pagtoweb*` para `PagtoWeb*` e declaram `$table = 'pagtoweb_*'`. Não haverá migration nem alias de classe. Alternativas de manter o casing antigo ou criar aliases foram rejeitadas por perpetuarem o nome e porque PHP trata nomes de classe sem distinção de case.

### Quebras internas são coordenadas, contratos de produto não

Os sete Jobs perdem o prefixo `Communication` sem shim. O deploy só pode ocorrer depois de zerar jobs pendentes, reservados, atrasados e falhos que precisem de retry. `WAZYNC_WA_*` é substituído por `WAZYNC_WHATSAPP_*` sem fallback; todas as instâncias devem trocar a configuração no mesmo rollout.

### Nuxt é validado pelo nome realmente gerado

Arquivos de componentes perdem o substantivo repetido pelo diretório e todos os usos de tags são atualizados. Tipos Communication são primeiro separados por subdomínio e só então encurtados. O gate inspeciona o registry gerado, em vez de inferir nomes apenas pelo filesystem.

### Contratos são comparados semanticamente

O OpenAPI público pode registrar novos FQCNs em metadados textuais gerados; paths, métodos, `operationId`, schemas e respostas devem permanecer iguais. O OpenAPI privado Wazync deve permanecer byte a byte inalterado.

### Compatibilidade anterior é removida, não renomeada

O middleware que converte queries de navegador antigas é excluído, assim como os aliases de endpoint `WAZYNC_EVENTS_URL` e `WAZYNC_MEDIA_URL`. Fotos de perfil exigem `profile_picture_state = READY`; a ausência do estado não é mais interpretada como pronta. Arquivos, símbolos, comentários e testes não usam terminologia de compatibilidade anterior. A migration de remoção de colunas recebe basename canônico e operações idempotentes para não falhar quando o nome novo for observado por um banco que já executou o conteúdo sob o basename anterior. O rename só é permitido porque o basename anterior ainda não foi publicado no branch remoto; se já tivesse sido compartilhado, uma migration corretiva nova seria obrigatória.

## Risks / Trade-offs

- [Payload de fila referencia FQCN removido] → bloquear o rollout até a drenagem completa e não apagar falhas automaticamente.
- [Eloquent infere outra tabela após o casing] → declarar e testar `$table` nos seis Models.
- [Colisão após remover prefixo] → modularizar antes do rename e manter o qualificador quando o símbolo estiver em namespace global.
- [Auto-import Nuxt muda silenciosamente] → validar `.nuxt/components.d.ts`, typecheck, generate e gates de fidelidade/artefatos.
- [Configuração antiga derruba Wazync] → marcar o commit breaking e publicar checklist de troca simultânea das cinco variáveis.
- [Bookmarks com query deixam de ser convertidos] → emitir somente paths canônicos; queries de browser anteriores deixam de ter suporte deliberadamente.
- [Migration renomeada aparece como pendente] → tornar `up` e `down` idempotentes e validar banco limpo e banco com colunas já removidas.
- [Artefato gerado mascara mudança de contrato] → usar diff semântico do OpenAPI e testes de superfície existentes.

## Migration Plan

1. Publicar os commits e preparar `WAZYNC_WHATSAPP_*` em segredo/configuração externa, sem ativá-los antecipadamente.
2. Parar produtores e workers na versão antiga; aguardar filas pendentes, reservadas e atrasadas chegarem a zero.
3. Resolver sob a versão antiga qualquer failed job que ainda precise de retry; não excluir registros automaticamente.
4. Implantar API, workers, scheduler e Wazync em conjunto, com a configuração nova.
5. Reiniciar produtores/workers e validar readiness, Horizon e métricas internas sem payload sensível.
6. Em rollback, parar novamente produtores/workers, drenar jobs criados pela versão nova e restaurar binários e variáveis antigas em conjunto.

## Open Questions

Nenhuma. O usuário escolheu explicitamente a quebra coordenada, sem aliases de FQCN ou configuração.
