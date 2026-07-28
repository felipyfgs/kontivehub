## Context

Na stack local observada em 2026-07-28, o Nginx permaneceu com o upstream
FastCGI `172.19.0.6:9000` após o PHP ser recriado em `172.19.0.3`. O DNS interno
do Compose já resolvia `php` para o endereço novo e a porta 9000 estava aberta,
mas `fastcgi_pass php:9000` havia sido resolvido apenas na inicialização do
Nginx. O healthcheck `GET /up` acumulou mais de 240 falhas consecutivas com
`502`, enquanto o container PHP estava saudável.

Os arquivos de desenvolvimento e produção ainda resolviam PHP e Reverb
estaticamente na inicialização do Nginx. A mudança aplica o resolver Docker e
variáveis aos dois upstreams internos, preservando suas superfícies públicas e
sem depender de restart manual do edge para convergir.

## Goals / Non-Goals

**Goals:**

- Fazer o Nginx convergir para o endereço atual de `php:9000` após recriação do
  container PHP.
- Fazer os proxies do Reverb voltarem a resolver `reverb:8081` quando o
  container mudar de endereço, sem alterar rotas ou timeouts.
- Preservar `/up` como verificação ponta a ponta Nginx → PHP, com falha
  transitória durante indisponibilidade e recuperação automática posterior.
- Aplicar a mesma política aos arquivos de desenvolvimento e produção.
- Provar o comportamento com validação de configuração e teste operacional
  reproduzível.

**Non-Goals:**

- Alterar rotas, envelopes, autenticação, tenancy ou contratos OpenAPI.
- Mascarar indisponibilidade real do PHP com uma resposta saudável gerada pelo
  próprio Nginx.
- Executar deploy, restart produtivo, go-live ou alterar políticas do Reverb.
- Corrigir retroativamente mensagens antigas de schema presentes nos logs; a
  stack atual não possui migrations pendentes.

## Decisions

### 1. Resolver o upstream FastCGI por variável

Cada `location @laravel` usará uma variável com o endereço lógico `php:9000` e
`fastcgi_pass` receberá essa variável. O servidor também declarará
`resolver 127.0.0.11` com TTL limitado e `ipv6=off`, permitindo nova consulta
ao DNS interno quando o cache expirar. O mesmo padrão será aplicado ao
`proxy_pass` do Reverb.

Alternativa considerada: reiniciar o Nginx sempre que o PHP mudar. Rejeitada
porque cria coordenação operacional frágil e mantém o edge indisponível quando
um restart isolado ocorre fora desse fluxo.

Alternativa considerada: `upstream` com parâmetro `resolve`. Embora adequado em
versões recentes, ele depende das capacidades exatas do build Nginx e de zona
compartilhada. A variável preserva compatibilidade com a imagem atual e replica
o padrão já usado para Reverb.

### 2. Manter `/up` como healthcheck integrado

O healthcheck continuará chamando `/up` no próprio Nginx. Ele deve falhar
enquanto o PHP realmente estiver indisponível e voltar a passar após a
resolução DNS convergir. Não será criado endpoint estático de liveness que
declare o edge saudável sem alcançar a aplicação.

### 3. Validar comportamento, não apenas texto de configuração

Os gates incluirão `nginx -t` para os dois arquivos e um teste local que:

1. confirma `/up` saudável;
2. recria somente o serviço PHP;
3. mantém o mesmo container Nginx;
4. aguarda uma janela limitada;
5. exige `/up` saudável novamente e confirma que o Nginx não reiniciou.

O teste terá timeout e diagnóstico sanitizado, sem imprimir respostas da
aplicação, headers de autenticação ou payloads.

## Risks / Trade-offs

- **[Risco] Consulta DNS falhar durante transição** → o Nginx retorna `502`
  fail-closed e o healthcheck permanece degradado até a resolução convergir.
- **[Risco] Pequeno custo adicional de resolução** → usar o TTL limitado já
  configurado, sem consulta nova a cada pacote FastCGI.
- **[Risco] Sintaxe divergente entre imagens dev/prod** → validar ambos os
  arquivos contra a imagem Nginx efetivamente usada antes do handoff.
- **[Risco] Teste operacional interferir na stack compartilhada** → executá-lo
  somente de forma explícita, registrar IDs dos containers e não remover
  volumes, bancos ou outros serviços.

## Migration Plan

1. Alterar os dois arquivos Nginx e validar a sintaxe em container.
2. Executar o teste de recriação na stack local deste checkout.
3. Confirmar os gates normais de API/edge e ausência de alteração de contrato.
4. Em rollout autorizado, publicar a configuração e observar `/up` e taxa de
   `502`; nenhum comando produtivo faz parte da implementação local.

Rollback consiste em restaurar apenas a forma anterior de `fastcgi_pass`. Não
há alteração de dados, migration ou estado persistente.

## Open Questions

- A imagem Nginx deve ser fixada em patch específico após a validação, para
  evitar diferenças futuras de resolução entre rebuilds?
