#!/bin/sh

load_env_secret() {
    secret_path=$1

    if [ ! -r "$secret_path" ]; then
        echo "Docker Secret não legível: $secret_path" >&2
        return 78
    fi

    line_number=0
    while IFS= read -r secret_line || [ -n "$secret_line" ]; do
        line_number=$((line_number + 1))

        case "$secret_line" in
            ''|'#'*)
                continue
                ;;
            *=*)
                ;;
            *)
                echo "Linha inválida no Docker Secret $secret_path:$line_number" >&2
                return 78
                ;;
        esac

        secret_key=${secret_line%%=*}
        secret_value=${secret_line#*=}

        case "$secret_key" in
            ''|[0-9]*|*[!A-Z0-9_]*)
                echo "Chave inválida no Docker Secret $secret_path:$line_number" >&2
                return 78
                ;;
        esac

        # Configuração da aplicação pode ser extensa, mas o secret não pode
        # alterar resolução de comandos, loaders dinâmicos ou bootstraps das
        # linguagens executadas pelo container.
        case "$secret_key" in
            PATH|HOME|IFS|ENV|BASH_ENV|SHELLOPTS|PS4|CDPATH|GLOBIGNORE|TMPDIR|\
            LD_*|DYLD_*|GCONV_PATH|GETCONF_DIR|HOSTALIASES|LOCPATH|NLSPATH|\
            PHPRC|PHP_INI_DIR|PHP_INI_SCAN_DIR|OPENSSL_CONF|OPENSSL_MODULES|\
            PYTHONHOME|PYTHONPATH|PYTHONSTARTUP|PYTHONINSPECT|PYTHONWARNINGS|\
            PYTHONBREAKPOINT|PERL5OPT|PERL5LIB|RUBYOPT|RUBYLIB|NODE_OPTIONS|\
            NODE_PATH|JAVA_TOOL_OPTIONS|_JAVA_OPTIONS)
                echo "Chave de processo proibida no Docker Secret $secret_path:$line_number" >&2
                return 78
                ;;
        esac

        case "$secret_value" in
            \'*\')
                secret_value=${secret_value#\'}
                secret_value=${secret_value%\'}
                ;;
            \"*\")
                secret_value=${secret_value#\"}
                secret_value=${secret_value%\"}
                ;;
            \'*|*\'|\"*|*\")
                echo "Aspas não balanceadas no Docker Secret $secret_path:$line_number" >&2
                return 78
                ;;
        esac

        # export recebe um único argumento já expandido; o valor nunca é avaliado
        # como código de shell.
        export "$secret_key=$secret_value"
    done < "$secret_path"
}
