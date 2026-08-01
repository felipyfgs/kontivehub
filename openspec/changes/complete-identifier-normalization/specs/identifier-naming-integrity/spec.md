## ADDED Requirements

### Requirement: Identificadores contextuais e inequívocos

O repositório SHALL evitar prefixos que apenas repetem o namespace ou diretório quando sua remoção mantém o símbolo inequívoco, e SHALL preservar qualificadores necessários em namespaces globais ou contratos.

#### Scenario: Símbolo isolado por módulo
- **WHEN** uma classe ou componente possui prefixo idêntico ao módulo que já o contém
- **THEN** seu arquivo, símbolo e consumidores usam o nome contextual sem repetir o módulo

#### Scenario: Qualificador necessário
- **WHEN** remover um prefixo causar colisão, ambiguidade ou alterar contrato observável
- **THEN** o qualificador permanece e sua exceção é registrada no inventário

### Requirement: Casing canônico sem migração de dados

Identificadores internos SHALL usar `PagtoWeb` e `WhatsApp`, enquanto tabelas e contratos persistidos existentes SHALL manter seus nomes canônicos atuais.

#### Scenario: Model PagtoWeb renomeado
- **WHEN** um Model `Pagtoweb*` for normalizado para `PagtoWeb*`
- **THEN** ele declara explicitamente a mesma tabela `pagtoweb_*` e lê e grava os mesmos registros

#### Scenario: Termos de protocolo preservados
- **WHEN** a varredura alcançar JID, MIME, WhatsMeow, HMAC ou valores do contrato Wazync
- **THEN** esses termos e valores permanecem inalterados

### Requirement: Compatibilidade observável

A normalização SHALL preservar URIs, métodos, campos JSON, status, `operationId`, schemas, backing values, isolamento tenant e o contrato privado Laravel-Wazync.

#### Scenario: Contrato público após renomear classes
- **WHEN** Controllers, Requests ou Resources mudarem de FQCN
- **THEN** o contrato público permanece semanticamente equivalente, exceto por metadados textuais de implementação

#### Scenario: Contrato privado após renomear o gateway
- **WHEN** identificadores internos e configuração do Wazync forem renomeados
- **THEN** o OpenAPI privado, HMAC, rotas, comandos, queries e eventos permanecem byte a byte equivalentes

### Requirement: Quebra interna com rollout coordenado

FQCNs antigos de Jobs e Models e variáveis `WAZYNC_WA_*` SHALL deixar de ser aceitos somente mediante drenagem de filas e atualização simultânea das instâncias.

#### Scenario: Deploy bloqueado por payload antigo
- **WHEN** existir job pendente, reservado, atrasado ou falho que ainda precise de retry com FQCN antigo
- **THEN** o rollout permanece bloqueado e nenhum registro é apagado automaticamente

#### Scenario: Configuração Wazync coordenada
- **WHEN** a versão normalizada do Wazync for implantada
- **THEN** todas as cinco variáveis `WAZYNC_WHATSAPP_*` estão presentes no ambiente no mesmo rollout

### Requirement: Prevenção de regressão

Os gates SHALL detectar as duplicações contextuais e casing obsoleto cobertos pelo inventário, além de validar os nomes realmente gerados pelo Nuxt.

#### Scenario: Nome redundante reintroduzido
- **WHEN** um identificador voltar a usar uma família proibida pelo inventário
- **THEN** um teste arquitetural ou busca residual falha antes do commit

#### Scenario: Registry Nuxt gerado
- **WHEN** os componentes forem preparados ou gerados
- **THEN** o registry não contém segmentos duplicados como `FlowsFlow`, `ContactsContact`, `QuickResponsesQuickResponse` ou `PgdasdPgdasd`

### Requirement: Somente interfaces canônicas são aceitas

O sistema SHALL remover adaptadores, aliases e fallbacks destinados a formatos anteriores, e SHALL manter arquivos e identificadores livres dos termos `legacy` e `legado`.

#### Scenario: Navegação Web não canônica
- **WHEN** uma URL de browser antiga usar query para transportar estado de tela
- **THEN** a SPA não converte essa query em estado, intenção ou path canônico

#### Scenario: Configuração Wazync não canônica
- **WHEN** apenas `WAZYNC_EVENTS_URL` ou `WAZYNC_MEDIA_URL` estiver definido
- **THEN** o Wazync permanece sem endpoint correspondente e falha fechado quando habilitado

#### Scenario: Foto sem estado explícito
- **WHEN** uma projeção possuir URL de foto mas não possuir estado `READY`
- **THEN** a SPA não usa a URL

#### Scenario: Varredura textual e de filenames
- **WHEN** os gates de naming inspecionarem código, testes, migrations e documentação versionada
- **THEN** nenhuma ocorrência ou basename contém `legacy` ou `legado`, sem substituir palavras cegamente dentro de código
