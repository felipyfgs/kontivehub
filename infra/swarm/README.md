# Produção no Docker Swarm

O arquivo `docker-stack.yml` descreve somente o runtime de produção. Ele não
possui `build`, bind mount do repositório, hot reload ou ferramenta de
desenvolvimento. As imagens `api`, `api-rpa`, `web`, `nginx` e `wazync` são
publicadas no GHCR pelo workflow `Publish production images` depois que o CI da
branch `main` termina com sucesso.

PostgreSQL e Redis continuam serviços independentes usando imagens oficiais.
Os volumes declarados na stack são externos para impedir que a remoção da
stack apague dados. Em um Swarm com mais de um nó, eles devem usar um driver de
volume compartilhado ou restrições de posicionamento compatíveis com a
infraestrutura escolhida.

A rede `app` é interna e mantém PostgreSQL e Redis sem saída externa. Somente
API, Horizon, scheduler e Wazync também entram na rede `egress`, necessária
para integrações como SEFAZ, WhatsApp e provedores externos. Nenhuma dessas
redes publica portas diretamente; o único ponto de entrada é o Nginx.

## Preparação

Crie arquivos reais fora do repositório a partir dos exemplos em `secrets/`.
Os arquivos `*-runtime.env` contêm apenas dados no formato `CHAVE=valor`; o
runtime usa um parser que não avalia construções de shell. Valores podem ser
colocados entre aspas simples ou duplas, que são removidas como delimitadores.
Não versione os arquivos preenchidos.

Crie os secrets uma única vez:

```bash
docker secret create api_runtime_env /caminho-seguro/api-runtime.env
docker secret create wazync_runtime_env /caminho-seguro/wazync-runtime.env
docker secret create postgres_db /caminho-seguro/postgres-db
docker secret create postgres_user /caminho-seguro/postgres-user
docker secret create postgres_password /caminho-seguro/postgres-password
docker secret create redis_conf /caminho-seguro/redis.conf
openssl rand -hex 32 | docker secret create nginx_edge_token -
```

Crie volumes persistentes usando o driver aprovado para o cluster:

```bash
docker volume create postgres_data
docker volume create redis_data
docker volume create vault_data
docker volume create private_storage
docker volume create wazync_spool
```

O usuário e o schema exclusivos do Wazync devem ser provisionados no
PostgreSQL antes de habilitar `WAZYNC_ENABLED=true`. A URL correspondente fica
somente no secret `wazync_runtime_env`.

## Implantação

Autentique cada nó do Swarm no registry e use a tag `sha-*` publicada uma única
vez pela pipeline. O workflow não sobrescreve uma tag de revisão existente:

```bash
export KONTIVEHUB_REGISTRY=ghcr.io/organizacao/repositorio
export KONTIVEHUB_VERSION=sha-COMMIT_COMPLETO
docker stack config -c docker-stack.yml >/dev/null
docker stack deploy --with-registry-auth -c docker-stack.yml kontivehub
```

As duas variáveis acima identificam o endereço e a versão das imagens; a
configuração da aplicação não passa pelo arquivo da stack. Todo o runtime é
carregado dos Docker Secrets montados em `/run/secrets`.

O Nginx publica HTTP na porta 80 exclusivamente para um balanceador/ingress
externo. TLS deve terminar nesse componente. Além do firewall, o balanceador
deve sobrescrever (nunca apenas encaminhar) o header
`X-KontiveHub-Edge-Token` com o mesmo valor do secret `nginx_edge_token`.
Tráfego na porta publicada sem esse token recebe `421`; healthchecks e chamadas
HMAC do Wazync usam a porta 8080, disponível somente na rede overlay interna.
Depois de validar o token, o proxy marca a requisição como HTTPS antes de
entregá-la ao Laravel. Certificados, chaves privadas e o token do edge devem
permanecer em Docker Secrets, nunca incorporados às imagens.

Antes de atualizar serviços, execute migrations com a mesma imagem e o mesmo
secret da versão que será implantada. Em clusters compatíveis com jobs:

```bash
docker service create \
  --name kontivehub-migrate-KONTIVEHUB_VERSION \
  --mode replicated-job \
  --restart-condition none \
  --network kontivehub_app \
  --secret source=api_runtime_env,target=api_runtime_env \
  ghcr.io/organizacao/repositorio/api:sha-COMMIT_COMPLETO \
  php artisan migrate --force
```

Só prossiga com `docker stack deploy` depois que o job terminar com sucesso.
O Swarm aplica healthchecks, limites de recursos, reinício e atualização gradual
definidos na stack. `failure_action: rollback` reverte falhas de tasks durante a
janela de monitoramento; o estado `unhealthy`, isoladamente, não substitui a
verificação do deploy. A automação operacional deve aguardar a convergência,
validar os healthchecks e reimplantar uma tag `sha-*` anterior se algum serviço
permanecer indisponível. Não reutilize tags mutáveis em produção.
