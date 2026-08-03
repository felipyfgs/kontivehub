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
infraestrutura escolhida. A stack exige o label de nó
`kontivehub.persistence=true` em todo serviço que monta estado persistente.
Marque exatamente um nó quando usar volumes locais; com NFS/CSI ou outro
driver compartilhado, marque todos os nós configurados para esse driver.

A rede `app` é interna e mantém PostgreSQL e Redis sem saída externa. Todas as
redes overlay usam criptografia do Swarm, inclusive o tráfego PostgreSQL entre
nós; por isso os clientes internos podem usar `sslmode=disable` sem expor o
tráfego na rede física. API, Horizon, scheduler e Wazync também entram na rede
`egress`, necessária para integrações como SEFAZ, WhatsApp e provedores
externos. Nenhuma dessas redes publica portas diretamente; o único ponto de
entrada é o Nginx.

## Preparação

Crie arquivos reais fora do repositório a partir dos exemplos em `secrets/`.
Os arquivos `*-runtime.env` contêm apenas dados no formato `CHAVE=valor`; o
runtime usa um parser que não avalia construções de shell. Valores podem ser
colocados entre aspas simples ou duplas, que são removidas como delimitadores.
O secret `redis_password` contém somente a senha em uma única linha, sem aspas.
Não versione os arquivos preenchidos.

Crie os secrets uma única vez:

```bash
docker secret create api_runtime_env /caminho-seguro/api-runtime.env
docker secret create wazync_runtime_env /caminho-seguro/wazync-runtime.env
docker secret create postgres_db /caminho-seguro/postgres-db
docker secret create postgres_user /caminho-seguro/postgres-user
docker secret create postgres_password /caminho-seguro/postgres-password
docker secret create redis_conf /caminho-seguro/redis.conf
docker secret create redis_password /caminho-seguro/redis-password
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

Autorize os nós que podem montar esses volumes. Para volumes locais, execute o
comando abaixo em exatamente um nó; para volumes compartilhados, repita para
cada nó preparado com o mesmo driver:

```bash
docker node update --label-add kontivehub.persistence=true NOME_DO_NO
```

O usuário e o schema exclusivos do Wazync devem ser provisionados no
PostgreSQL antes de habilitar `WAZYNC_ENABLED=true`. A URL correspondente fica
somente no secret `wazync_runtime_env`.

## Implantação

Autentique cada nó do Swarm no registry e use a tag imutável `sha-*-cfg-*`
publicada pela pipeline. O sufixo `cfg` identifica também a configuração pública
incorporada à imagem Web; o workflow não sobrescreve uma tag existente:

```bash
export KONTIVEHUB_REGISTRY=ghcr.io/organizacao/repositorio
export KONTIVEHUB_VERSION=sha-COMMIT_COMPLETO-cfg-DIGEST12
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
run_kontivehub_migrations() (
  set -eu
  MIGRATION_SERVICE="kontivehub-migrate-$(date -u +%Y%m%d%H%M%S)-$$"
  MIGRATION_DEADLINE=$(( $(date +%s) + 900 ))

  docker service create \
    --detach=true \
    --name "$MIGRATION_SERVICE" \
    --mode replicated-job \
    --restart-condition none \
    --network kontivehub_app \
    --secret source=api_runtime_env,target=api_runtime_env \
    "${KONTIVEHUB_REGISTRY}/api:${KONTIVEHUB_VERSION}" \
    php artisan migrate --force

  trap 'echo "Interrompido; preserve $MIGRATION_SERVICE para diagnóstico." >&2; exit 130' INT
  trap 'echo "Encerrado; preserve $MIGRATION_SERVICE para diagnóstico." >&2; exit 143' TERM

  while :; do
    if ! MIGRATION_TASKS=$(docker service ps --no-trunc \
      --format '{{.ID}}' "$MIGRATION_SERVICE"); then
      echo "Não foi possível consultar $MIGRATION_SERVICE; serviço preservado." >&2
      exit 1
    fi
    MIGRATION_TASK=$(printf '%s\n' "$MIGRATION_TASKS" | awk 'NR == 1 { print $1 }')

    if [ -n "$MIGRATION_TASK" ]; then
      if ! MIGRATION_STATE=$(docker inspect \
        --format '{{.Status.State}}' "$MIGRATION_TASK"); then
        echo "Não foi possível inspecionar $MIGRATION_TASK; serviço preservado." >&2
        exit 1
      fi

      case "$MIGRATION_STATE" in
        complete)
          trap - INT TERM
          docker service rm "$MIGRATION_SERVICE"
          echo "Migration concluída com sucesso."
          exit 0
          ;;
        failed|rejected|shutdown|orphaned|remove)
          docker service ps --no-trunc "$MIGRATION_SERVICE" >&2 || true
          docker service logs --tail 100 "$MIGRATION_SERVICE" >&2 || true
          echo "Migration terminou em $MIGRATION_STATE; serviço preservado." >&2
          exit 1
          ;;
        new|pending|assigned|accepted|preparing|ready|starting|running)
          ;;
        *)
          echo "Estado inesperado '$MIGRATION_STATE'; serviço preservado." >&2
          exit 1
          ;;
      esac
    fi

    if [ "$(date +%s)" -ge "$MIGRATION_DEADLINE" ]; then
      echo "Timeout aguardando migrations; serviço preservado." >&2
      exit 124
    fi
    sleep 2
  done
)

run_kontivehub_migrations
```

O polling aguarda no máximo 15 minutos e remove o serviço somente após o estado
`complete`. Falha, timeout ou interrupção retornam código diferente de zero e
preservam o job para diagnóstico. Só prossiga com `docker stack deploy` depois
que a função terminar com sucesso.
O Swarm aplica healthchecks, limites de recursos, reinício e atualização gradual
definidos na stack. `failure_action: rollback` reverte falhas de tasks durante a
janela de monitoramento; o estado `unhealthy`, isoladamente, não substitui a
verificação do deploy. A automação operacional deve aguardar a convergência,
validar os healthchecks e o readiness ponta a ponta em `/up`, e reimplantar uma
tag `sha-*-cfg-*` anterior se algum serviço permanecer indisponível. O healthcheck
`/nginx-health` valida somente o processo do proxy; indisponibilidade do PHP é
tratada pelo probe próprio do serviço e aparece no readiness `/up`, sem provocar
reinícios inúteis de tasks saudáveis do Nginx. Não reutilize tags mutáveis em
produção.
