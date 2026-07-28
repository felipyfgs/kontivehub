## Why

O Nginx mantém o IP resolvido de `php:9000` desde sua inicialização. Quando o
container PHP é recriado com outro IP, o Nginx continua enviando FastCGI ao
endereço antigo, `/up` passa a responder `502` e o edge fica `unhealthy` mesmo
com o PHP saudável e acessível pelo DNS do Compose.

## What Changes

- Tornar a resolução do upstream PHP dinâmica nos arquivos Nginx de
  desenvolvimento e produção, usando o DNS interno do Docker com janela de
  atualização limitada.
- Preservar os parâmetros FastCGI, timeouts, buffers, allowlists e superfícies
  públicas atuais.
- Adicionar uma verificação reproduzível que recria somente o PHP, mantém o
  Nginx em execução e exige recuperação de `/up` sem restart manual do edge.
- Documentar diagnóstico e recuperação segura para indisponibilidade do
  upstream, sem transformar restart em mecanismo normal de convergência.

## Capabilities

### New Capabilities

- `container-edge-availability`: Resolução e recuperação dos upstreams internos
  do Nginx quando containers do Compose mudam de endereço.

### Modified Capabilities

Nenhuma.

## Impact

- Código afetado: `infra/docker/nginx/conf/dev.conf`,
  `infra/docker/nginx/conf/prod.conf`, Compose/Make somente se necessário para a
  verificação e testes operacionais de infraestrutura.
- Sistemas afetados: Nginx e PHP-FPM nas redes internas do Compose.
- Contratos HTTP, Laravel, Nuxt, Wazync e OpenAPI permanecem inalterados.
- Não há migration, nova dependência, egress, alteração de flag ou rollout de
  produção nesta change. Qualquer comando produtivo permanece fora do escopo
  sem autorização explícita.
