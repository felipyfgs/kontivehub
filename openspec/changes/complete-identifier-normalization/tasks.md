## 1. Baseline e arquitetura

- [x] 1.1 Registrar proposta, design, spec e inventário completo da segunda onda
- [ ] 1.2 Adicionar gates arquiteturais para famílias redundantes, casing obsoleto e registry Nuxt

## 2. API Laravel

- [ ] 2.1 Normalizar Requests Communication, Tenant e Work, incluindo bases e hooks
- [ ] 2.2 Normalizar Actions Tenant/Work, Controllers Work, Resources Work e Enums Communication
- [ ] 2.3 Renomear Jobs Communication e Models PagtoWeb com tabelas explícitas
- [ ] 2.4 Corrigir variáveis e métodos privados residuais e atualizar todos os consumidores e artefatos
- [ ] 2.5 Executar testes focados e gates completos da API

## 3. Web Nuxt

- [ ] 3.1 Renomear componentes redundantes e atualizar todos os auto-imports e testes textuais
- [ ] 3.2 Renomear o workspace e modularizar os tipos Communication sem colisões
- [ ] 3.3 Validar o registry gerado e executar todos os gates Web

## 4. Wazync

- [ ] 4.1 Padronizar campos de configuração WhatsApp e migrar `WAZYNC_WA_*`
- [ ] 4.2 Renomear MediaSource, URLs contextuais e doubles de spool, preservando o contrato privado
- [ ] 4.3 Executar `gofmt`, testes focados e `make wazync-test`

## 5. Integração e entrega

- [ ] 5.1 Executar buscas residuais e validar os contratos público e privado
- [ ] 5.2 Rodar review automático, corrigir achados reais e repetir até limpar ou três ciclos
- [ ] 5.3 Criar commits atômicos em pt-BR sem artefatos locais e sem push

## 6. Remoção de compatibilidade anterior

- [ ] 6.1 Excluir middleware e testes de queries de browser não canônicas
- [ ] 6.2 Remover aliases de endpoint Wazync e fallback de foto sem estado `READY`
- [ ] 6.3 Renomear a migration com operações idempotentes e remover terminologia de transição de código, testes e OpenSpec versionado
- [ ] 6.4 Adicionar gate textual e de filenames e repetir testes afetados
