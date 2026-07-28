## Evidência local

Validado em 2026-07-28 na stack de desenvolvimento deste checkout.

### Configuração

- `sh -n infra/docker/nginx/verify-upstream-recovery.sh`: passou.
- `nginx -t` com `nginx:1.27-alpine` para `dev.conf` e `prod.conf`: passou.
- `docker compose config --quiet`: passou.
- Desenvolvimento e produção mantêm resolver Docker com TTL de 10 segundos,
  `fastcgi_read_timeout 120s`, buffers, parâmetros FastCGI e restrições de rota.
- `/up` permanece restrito à rede local no desenvolvimento e negado no servidor
  público de produção.
- Os upstreams Reverb passaram a usar a mesma resolução dinâmica; suas rotas,
  headers e timeouts permaneceram inalterados.

### Convergência

`make nginx-upstream-test` comprovou:

- `/up` saudável antes da operação;
- falha de `/up` enquanto o PHP estava indisponível;
- novo container PHP com endereço diferente;
- mesmo container Nginx durante toda a verificação;
- recuperação automática de `/up` e health `healthy`;
- remoção do container auxiliar e ausência de remoção de volumes.

Após a convergência, o DNS de `php` dentro do Nginx coincidiu com o endereço do
container atual, `/up` respondeu `200` e não houve novo `502` no minuto
observado.

### Gates da API

- `composer validate --strict --no-check-publish`: passou.
- `vendor/bin/pint --test`: passou em 2.652 arquivos.
- Testes focados de Communication e arquitetura: 8 testes, 114 asserções.
- `git diff --check`: passou.
- Validação OpenSpec estrita: passou.
