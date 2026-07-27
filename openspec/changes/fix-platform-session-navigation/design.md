## Context

O domínio já separa corretamente a autorização global
(`platform_memberships`) da autorização tenant (`tenant_memberships`). O seed
cria o proprietário somente na primeira estrutura e aponta
`default_tenant_id` para o tenant inicial. Em runtime, o contexto privilegiado
resolve esse tenant sem fabricar uma membership.

Dois defeitos independentes quebram a apresentação desse estado:

1. arquivos PHP novos foram criados com modo `0600`, enquanto o PHP-FPM lê o
   bind mount como usuário não proprietário;
2. o cliente Nuxt usa `/platform/tenants`, cuja resposta administrativa é uma
   lista, mas a interpreta como o envelope do seletor disponível em
   `/platform/tenants/selector`.

## Goals / Non-Goals

**Goals:**

- entregar uma identidade `/me` íntegra via HTTP;
- listar e selecionar tenants pelo contrato privilegiado canônico;
- exibir os módulos globais para `platform_admin`;
- manter chaves estrangeiras e boundaries atuais de autorização;
- detectar regressões no backend e frontend.

**Non-Goals:**

- criar `tenant_memberships` para o proprietário;
- alterar o schema PostgreSQL;
- redesenhar o shell ou criar novos módulos;
- habilitar integrações fiscais reais.

## Decisions

- Manter `PlatformMembership::default_tenant_id` como chave do contexto inicial.
  Uma membership tenant fictícia misturaria autorização global com autorização
  operacional e violaria o boundary existente.
- Corrigir o cliente para chamar explicitamente
  `/api/v1/platform/tenants/selector`. Reformatar a resposta administrativa
  duplicaria contratos e manteria a ambiguidade.
- Normalizar para `0644` somente os arquivos PHP novos do change/seed. Não será
  alterada a política de permissões de `.env`, certificados ou outros segredos.
- Testar o contrato HTTP e o caminho usado pelo cliente, além das funções puras
  de navegação já existentes.

## Risks / Trade-offs

- [Cache de identidade antigo no navegador] → o refresh de `/me` e um novo login
  passam a receber JSON válido; o seletor força refresh após troca.
- [Confusão entre lista administrativa e seletor] → manter endpoints e tipos
  distintos e testar a URL literal usada pelo cliente.
- [Ampliação indevida do acesso global] → nenhuma regra de autorização ou
  foreign key é relaxada; apenas o contrato já autorizado volta a ser consumido.
