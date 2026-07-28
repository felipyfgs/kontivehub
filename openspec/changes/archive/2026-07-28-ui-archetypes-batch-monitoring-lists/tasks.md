## 1. Preparação

- [x] 1.1 Validar a referência do dashboard e reler o arquétipo `customers.vue`
- [x] 1.2 Confirmar o inventário das dez superfícies, slots, testids, matriz e allowlists

## 2. Chrome compartilhado

- [x] 2.1 Migrar `MonitoringModuleTable` para `ShellPagePanel` e `ShellPageNavbar`
- [x] 2.2 Preservar a consulta pendente condicional e adicionar `ShellNavbarRefresh`
- [x] 2.3 Remover somente o sidebar collapse duplicado, mantendo toda a árvore do body

## 3. Estados e contratos

- [x] 3.1 Usar `ShellLoadError` apenas no erro inicial sem linhas
- [x] 3.2 Preservar alerta stale, última carga válida, retry, slots, emits, filtros, seleção e paginação
- [x] 3.3 Manter adapters e KPI strip fiscal inalterados

## 4. Testes e gates

- [x] 4.1 Adicionar gate focado do chrome, erros, slots e identificadores compartilhados
- [x] 4.2 Rodar testes focados das carteiras e contratos de tabela
- [x] 4.3 Rodar lint, typecheck, generate, test, fidelity e artifacts
- [x] 4.4 Inspecionar desktop/mobile, foco, labels, loading, erro e vazio sem usar o runner destrutivo

## 5. Encerramento

- [x] 5.1 Confirmar matriz e allowlists inalteradas e marcar o Lote 2 no guarda-chuva
- [x] 5.2 Sincronizar `ui-archetypes-monitoring-lists`, validar strict e arquivar
