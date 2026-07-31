## 1. Navegação persistente

- [x] 1.1 Criar o outlet pai de Communication e o predicado testado das rotas que usam o workspace de conversas
- [x] 1.2 Tornar páginas de lista, conversa, mensagem e histórico por contato filhas finas sem instâncias duplicadas do workspace
- [x] 1.3 Atualizar a matriz de paridade e os gates de composição para a nova ownership do shell

## 2. Preparação e cache da timeline

- [x] 2.1 Deduplicar requests da timeline inicial e reutilizar timeline inicializada com refresh silencioso
- [x] 2.2 Fazer o prefetch carregar detalhe e primeira página real sem confirmar leitura
- [x] 2.3 Emitir a faixa visível da lista e processá-la em fila com concorrência limitada e limpeza por sessão/dispose
- [x] 2.4 Publicar a inicialização após a primeira lista e executar a sincronização cursorizada em background

## 3. Abertura atômica e movimento

- [x] 3.1 Comitar a seleção somente após preparar detalhe/timeline, preservando erro real e conteúdo anterior durante uma troca fria
- [x] 3.2 Remover skeleton e textos transitórios da troca de conversa sem afetar loading inicial, vazio ou paginação
- [x] 3.3 Desativar a transição do slideover mobile e adaptar a restauração de foco sem atraso fixo

## 4. Verificação

- [x] 4.1 Cobrir rota persistente, deduplicação/cache, prefetch visível, troca sem skeleton e comportamento mobile em testes focados
- [x] 4.2 Comparar desktop e mobile no app vivo, incluindo navegação rápida, deep-link, `Esc`, foco, falha e troca de tenant
- [x] 4.3 Executar lint, typecheck, generate, test, test:fidelity, test:artifacts, detector Impeccable e validação OpenSpec
- [x] 4.4 Executar o review automático, corrigir achados válidos e repetir os gates afetados
