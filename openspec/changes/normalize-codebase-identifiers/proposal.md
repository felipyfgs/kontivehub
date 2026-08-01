## Why

O monorepo acumulou identificadores que repetem o contexto já expresso por namespaces e diretórios, além de nomes enganosos, casing inconsistente e resíduos de branding anterior. Isso torna leitura, navegação e auto-imports desnecessariamente ruidosos e aumenta o custo de manutenção entre API, Web e Wazync.

## What Changes

- Simplificar nomes internos de classes, arquivos, funções, métodos, componentes, composables e tipos quando o contexto já estiver expresso pelo módulo.
- Padronizar casing e vocabulário interno, incluindo `WhatsApp`, `PagtoWeb`, `Communication` e termos de representação fiscal.
- Reorganizar tipos e módulos excessivamente grandes quando a modularização for necessária para evitar nomes globais redundantes.
- Preservar paths HTTP, `operationId`s, payloads, valores serializados, tabelas, migrations compartilhadas, métricas, headers HMAC e demais contratos externos.
- Manter compatibilidade temporária para nomes operacionais que possam existir em filas, configuração ou infraestrutura; mudanças de branding ficam condicionadas a aliases e rollout próprio.
- Atualizar testes, inventários e artefatos derivados legítimos para refletir os novos identificadores sem alterar o comportamento do produto.

## Capabilities

### New Capabilities

- `identifier-naming-compatibility`: Define as invariantes de nomenclatura interna e de preservação dos contratos observáveis durante refactors globais de identificadores.

### Modified Capabilities

Nenhuma capability funcional existente muda de comportamento.

## Impact

- API Laravel: DTOs, Resources, Actions, Services, Controllers, Jobs, imports, container e testes dos contextos Tenant, Work e Communication.
- Web Nuxt: componentes auto-importados, composables, helpers, tipos, consumidores e testes de inventário/fidelidade.
- Wazync Go: identificadores internos de spool, alertas e tipos de comando/query.
- Contratos e infraestrutura: somente quando for possível preservar consumidores por aliases; nenhuma migration compartilhada será editada.
- Não há habilitação de egress, capability real, automação ou kill switch.
