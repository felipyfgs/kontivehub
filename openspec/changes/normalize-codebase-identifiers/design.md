## Context

Os módulos `Tenant`, `Work` e `Communication` da API repetem o nome do contexto em classes já isoladas por namespace. No Nuxt, nomes de arquivos repetem diretórios e produzem auto-imports como `ClientsClient…` e `MonitoringAssociateMonitoring…`. O Wazync possui alguns identificadores que confundem mídia remota com spool local. Há ainda casing inconsistente para WhatsApp e PagtoWeb.

A mudança atravessa os três apps, mas não pretende alterar comportamento de produto. Contratos HTTP, valores serializados, persistência e nomes operacionais podem sobreviver a mais de uma versão e, portanto, não podem ser tratados como uma busca e substituição simples.

## Goals / Non-Goals

**Goals:**

- Fazer o nome local expressar apenas a responsabilidade que o namespace ou diretório ainda não informa.
- Renomear arquivos junto dos símbolos principais para manter PSR-4, auto-imports Nuxt e navegação do código coerentes.
- Padronizar inglês e casing oficial em identificadores internos.
- Atualizar todos os consumidores, testes e inventários versionados no mesmo lote.
- Demonstrar por testes que respostas, rotas, payloads, isolamento tenant e fronteiras entre apps permanecem iguais.

**Non-Goals:**

- Renomear migrations compartilhadas, tabelas, colunas, constraints ou schemas PostgreSQL.
- Alterar paths `/api/v1`, endpoints internos Wazync, `operationId`s, métricas, headers HMAC ou valores serializados.
- Reescrever textos oficiais de providers ou abreviar SERPRO, DEFIS, DCTFWeb, PGDAS-D, CCMEI, SVRS, NFe/NFCe, WhatsMeow, JID, PN e LID.
- Executar migração de recursos de produção, registry, redes ou volumes Docker.

## Decisions

### O contexto pertence ao módulo, não ao basename

Classes PHP sob `DTO\Tenant`, `DTO\Work` e `*\Communication`, componentes sob `components/clients` e símbolos locais de módulos TypeScript perderão prefixos que apenas repetem o caminho. Preferimos isso a abreviações, pois abreviações criariam um segundo vocabulário e reduziriam a busca textual.

### Contratos observáveis permanecem byte-for-byte estáveis

Renomeações internas não mudarão URIs, campos JSON, status, enum backing values, tipos de comando/evento, assinatura HMAC ou métricas. Controllers e Resources podem mudar de FQCN; o OpenAPI e seus `operationId`s não. Jobs serializados só serão renomeados quando houver alias de compatibilidade ou prova de que o nome não atravessa a fila.

### Nuxt será organizado antes de encurtar tipos ambíguos

Componentes serão renomeados segundo a composição oficial diretório + arquivo. Tipos de Communication só perderão prefixos quando forem movidos para módulos por subdomínio ou quando o novo nome continuar inequívoco no arquivo consumidor. Preferimos modularização a aliases globais, que apenas esconderiam a duplicação.

### Configuração aceita transição, não quebra abruptamente

Quando uma variável interna ganhar nome mais preciso, o nome novo terá precedência e o antigo será aceito temporariamente. Nomes de projeto, imagem, rede, volume e módulo Go ligados a branding serão documentados para rollout separado se não houver alias seguro no mesmo deploy.

### Refactor mecânico terá verificação semântica

Cada lote terá busca residual pelos padrões antigos, testes focados e gates do app. Artefatos derivados serão regenerados somente pelos scripts existentes; `.playwright/`, `.playwright-cli/`, `.nuxt` e `.output` não serão versionados.

## Risks / Trade-offs

- [FQCN de job já serializado deixa de resolver] → preservar aliases ou não renomear o job neste lote.
- [Colisão após remover prefixo] → verificar o namespace e os imports antes de cada família; manter qualificador semântico quando necessário.
- [Auto-import Nuxt muda silenciosamente] → renomear arquivo e todas as tags no mesmo lote, depois executar `generate`, typecheck e testes de fidelidade/artefatos.
- [Teste textual ou inventário fica obsoleto] → atualizar fontes e regenerar apenas artefatos versionados legítimos.
- [Variável de ambiente antiga deixa de funcionar] → aceitar novo e antigo nome com precedência documentada.
- [Diff muito amplo mascara mudança funcional] → proibir alterações de comportamento e revisar o diff por app antes dos gates globais.

## Migration Plan

1. Registrar os invariantes em testes e inventariar colisões.
2. Aplicar renomeações internas independentes por app.
3. Atualizar consumidores e remover referências residuais aos nomes antigos.
4. Executar gates API, Web e Wazync e comparar os contratos gerados.
5. Introduzir aliases para qualquer configuração ou FQCN que atravesse deploys.
6. Reverter o lote por app se um contrato observável mudar; não há migração de dados a desfazer.

## Open Questions

- O branding operacional `fiscal-hub` será migrado em change próprio após confirmação dos nomes de registry e dos recursos de produção.
- O conceito hoje chamado `Procuracao` será renomeado internamente somente após consolidar no glossário se o termo canônico é `FiscalRepresentation` ou `TaxProxy`.
