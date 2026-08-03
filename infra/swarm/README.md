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

Execute os comandos de controle abaixo em um manager do Swarm e valide o papel
antes de criar qualquer recurso:

```bash
if ! docker node ls >/dev/null 2>&1; then
  echo "Execute esta preparação em um manager do Docker Swarm." >&2
  exit 1
fi
```

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

Crie volumes persistentes informando explicitamente o driver aprovado. Substitua
`DRIVER_APROVADO` e acrescente os `--opt CHAVE=VALOR` exigidos pelo driver; para
volumes locais, defina `KONTIVEHUB_VOLUME_DRIVER=local` e autorize somente um nó:

Com o driver `local`, execute os comandos diretamente no Docker Engine do mesmo
nó que receberá `kontivehub.persistence=true` (por SSH ou Docker Context), não
apenas no manager. Com driver compartilhado, provisione o volume em todos os nós
que receberão o label, conforme as instruções do driver.

```bash
export KONTIVEHUB_VOLUME_DRIVER=DRIVER_APROVADO
docker volume create --driver "$KONTIVEHUB_VOLUME_DRIVER" --label kontivehub.persistence=true postgres_data
docker volume create --driver "$KONTIVEHUB_VOLUME_DRIVER" --label kontivehub.persistence=true redis_data
docker volume create --driver "$KONTIVEHUB_VOLUME_DRIVER" --label kontivehub.persistence=true vault_data
docker volume create --driver "$KONTIVEHUB_VOLUME_DRIVER" --label kontivehub.persistence=true private_storage
docker volume create --driver "$KONTIVEHUB_VOLUME_DRIVER" --label kontivehub.persistence=true wazync_spool
```

Autorize os nós que podem montar esses volumes. Para volumes locais, execute o
comando abaixo em exatamente um nó; para volumes compartilhados, repita para
cada nó preparado com o mesmo driver:

```bash
docker node update --label-add kontivehub.persistence=true NOME_DO_NO
```

Crie também a rede interna usada pela stack e pelo job de migration. Ela é
externa para existir antes do primeiro `docker stack deploy`; a criptografia
continua obrigatória:

```bash
if docker network inspect kontivehub_app >/dev/null 2>&1; then
  KONTIVEHUB_NETWORK_PROPERTIES=$(docker network inspect \
    --format '{{.Driver}} {{.Internal}}' \
    kontivehub_app)
  KONTIVEHUB_NETWORK_OPTIONS=$(docker network inspect \
    --format '{{json .Options}}' kontivehub_app)
  case "$KONTIVEHUB_NETWORK_OPTIONS" in
    *'"encrypted":""'*|*'"encrypted":"true"'*) ;;
    *)
      echo "kontivehub_app existe sem a opção encrypted." >&2
      exit 1
      ;;
  esac
  if [ "$KONTIVEHUB_NETWORK_PROPERTIES" != "overlay true" ]; then
    echo "kontivehub_app existe sem overlay internal criptografado." >&2
    exit 1
  fi
else
  docker network create \
    --driver overlay \
    --internal \
    --opt encrypted=true \
    kontivehub_app
fi
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

  validate_migration_network() {
    if ! docker network inspect kontivehub_app >/dev/null 2>&1; then
      echo "Rede externa kontivehub_app não encontrada; execute a preparação." >&2
      return 1
    fi
    MIGRATION_NETWORK_PROPERTIES=$(docker network inspect \
      --format '{{.Driver}} {{.Internal}}' kontivehub_app)
    MIGRATION_NETWORK_OPTIONS=$(docker network inspect \
      --format '{{json .Options}}' kontivehub_app)
    case "$MIGRATION_NETWORK_OPTIONS" in
      *'"encrypted":""'*|*'"encrypted":"true"'*) ;;
      *)
        echo "kontivehub_app não possui a opção encrypted." >&2
        return 1
        ;;
    esac
    if [ "$MIGRATION_NETWORK_PROPERTIES" != "overlay true" ]; then
      echo "kontivehub_app deve ser overlay internal criptografada." >&2
      return 1
    fi
  }

  migration_diagnostics() {
    docker service ps --no-trunc "$MIGRATION_SERVICE" >&2 || true
    docker service logs --tail 100 "$MIGRATION_SERVICE" >&2 || true
  }

  cancel_migration() {
    migration_diagnostics
    if ! docker service rm "$MIGRATION_SERVICE" >/dev/null; then
      echo "Falha ao cancelar $MIGRATION_SERVICE; intervenha imediatamente." >&2
      return 1
    fi
  }

  report_cancellation() {
    INTERRUPTION_MESSAGE=$1
    if cancel_migration; then
      echo "$INTERRUPTION_MESSAGE; job cancelado após diagnóstico." >&2
    else
      echo "$INTERRUPTION_MESSAGE; cancelamento falhou, intervenha imediatamente." >&2
    fi
  }

  trap 'report_cancellation "Interrompido"; exit 130' INT
  trap 'report_cancellation "Encerrado"; exit 143' TERM

  validate_migration_network

  docker service create \
    --detach=true \
    --name "$MIGRATION_SERVICE" \
    --mode replicated-job \
    --restart-condition none \
    --log-driver json-file \
    --log-opt max-size=10m \
    --log-opt max-file=3 \
    --network kontivehub_app \
    --secret source=api_runtime_env,target=api_runtime_env \
    "${KONTIVEHUB_REGISTRY}/api:${KONTIVEHUB_VERSION}" \
    php artisan migrate --force

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
          migration_diagnostics
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
      if cancel_migration; then
        echo "Timeout aguardando migrations; job cancelado após diagnóstico." >&2
      else
        echo "Timeout aguardando migrations; cancelamento falhou, intervenha imediatamente." >&2
      fi
      exit 124
    fi
    sleep 2
  done
)

run_kontivehub_migrations
docker stack deploy --with-registry-auth -c docker-stack.yml kontivehub
```

O polling aguarda no máximo 15 minutos e remove o serviço somente após o estado
`complete`. Falhas terminais preservam o job; timeout ou interrupção coletam o
diagnóstico e cancelam a task para impedir que migrations continuem em segundo
plano. Todos retornam código diferente de zero. Só prossiga com
`docker stack deploy` depois que a função terminar com sucesso.
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
