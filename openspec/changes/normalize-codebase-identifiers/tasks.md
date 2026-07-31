## 1. Baseline e invariantes

- [x] 1.1 Inventariar colisões, referências dinâmicas, nomes serializados e artefatos gerados antes das renomeações
- [x] 1.2 Adicionar ou ajustar testes que garantam estabilidade de contratos, rotas e auto-imports afetados

## 2. API Laravel

- [x] 2.1 Remover prefixos redundantes dos DTOs Tenant e Work, renomeando arquivos, classes e consumidores
- [x] 2.2 Remover prefixos redundantes dos Services Work, preservando bindings e responsabilidades
- [x] 2.3 Simplificar Resources, DTOs, Actions e Services de Communication sem alterar payloads
- [x] 2.4 Simplificar Controllers e outros identificadores internos de Communication, preservando rotas, OpenAPI e compatibilidade de filas
- [x] 2.5 Padronizar casing e vocabulário interno da API sem mudar valores serializados
- [x] 2.6 Executar buscas residuais, testes focados e gates completos da API

## 3. Web Nuxt

- [x] 3.1 Renomear componentes enganosos de Monitoring e atualizar todos os auto-imports e testes
- [x] 3.2 Remover a duplicação `ClientsClient` dos componentes sob `components/clients` em um lote atômico
- [x] 3.3 Simplificar composables, helpers, funções e casing inconsistente mantendo rotas e chamadas HTTP
- [x] 3.4 Modularizar tipos de Communication necessários para eliminar prefixos redundantes sem colisões
- [x] 3.5 Atualizar inventários e artefatos derivados versionados pelos scripts existentes
- [x] 3.6 Executar buscas residuais e todos os gates do Web

## 4. Wazync e configuração segura

- [x] 4.1 Renomear identificadores internos de spool, alertas, comandos e queries e executar `gofmt`
- [x] 4.2 Introduzir nomes precisos de configuração somente com aliases compatíveis e precedência testada
- [x] 4.3 Executar buscas residuais e `make wazync-test`

## 5. Integração e revisão

- [x] 5.1 Validar OpenSpec e confirmar que contratos públicos e privados não sofreram mudança incompatível
- [x] 5.2 Rodar code review automático, corrigir achados Critical/Warning reais e repetir até o diff ficar limpo
- [x] 5.3 Revisar o diff final por escopo, confirmar ausência de segredos/artefatos locais e documentar qualquer risco residual
