## 1. Preparação

- [x] 1.1 Validar a referência e reler `index.vue` e `home/*`
- [x] 1.2 Confirmar contratos, testids, CTAs, matriz e allowlists das duas páginas

## 2. Chrome do início

- [x] 2.1 Migrar `pages/index.vue` para `ShellPagePanel` e `ShellPageNavbar`
- [x] 2.2 Preservar alertas, ações rápidas e mover a toolbar para o slot canônico
- [x] 2.3 Substituir somente o refresh da toolbar por `ShellNavbarRefresh`

## 3. Chrome do monitoramento

- [x] 3.1 Migrar `pages/monitoring/index.vue` para os Shells existentes
- [x] 3.2 Preservar `overflow-x-hidden`, CTA por empresa e refresh acessível
- [x] 3.3 Manter erros inicial, stale e parcial, última leitura e KPI strip inalterados

## 4. Testes e gates

- [x] 4.1 Adicionar gate focado das duas cascas analíticas e ausência de chrome direto
- [x] 4.2 Rodar testes focados de início, insights, navegação e contratos de monitoring
- [x] 4.3 Rodar lint, typecheck, generate, test, fidelity e artifacts
- [x] 4.4 Inspecionar desktop/mobile, teclado, foco, labels, loading, erro, vazio e CTAs

## 5. Encerramento

- [x] 5.1 Confirmar matriz e allowlists inalteradas e marcar o Lote 3 no guarda-chuva
- [x] 5.2 Sincronizar `ui-archetypes-analytics`, validar strict e arquivar
