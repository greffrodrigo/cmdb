#!/bin/bash

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$(php -r '$c = require $argv[1]; echo $c["data_dir"];' "$APP_DIR/config.php")"
AMB_DIR="${AMBIENTES_DIR:-/etc/cmdb/ambientes}"
LEGADO_ENV="${PVE_ENV_FILE_LEGADO:-/etc/cmdb/proxmox.env}"
FILTRO="$1"
CODIGO_RE='^[a-z0-9][a-z0-9_-]*$'

if [[ -z "$DATA_DIR" ]]; then
    echo "Não foi possível ler data_dir de config.php" >&2
    exit 1
fi
if [[ -n "$FILTRO" && ! "$FILTRO" =~ $CODIGO_RE ]]; then
    echo "Ambiente inválido: $FILTRO" >&2
    exit 1
fi

mkdir -p "$DATA_DIR" || exit 1
LOCK="$DATA_DIR/coleta.lock"
STATUS="$DATA_DIR/status.json"
LOG="$DATA_DIR/coleta.log"
RES="$(mktemp)"
ENVTMP=""
trap 'rm -f "$RES" "$ENVTMP"' EXIT

exec 9>"$LOCK"
if ! flock -n 9; then
    echo "Já existe uma coleta em andamento." >&2
    exit 3
fi

INICIO="$(date -Iseconds)"

grava_status() {
    jq -n --arg e "$1" --arg m "$2" --arg i "$INICIO" --arg f "$(date -Iseconds)" --slurpfile r "$RES" \
        '{estado: $e, mensagem: $m, inicio: $i, atualizado_em: $f, ambientes: $r}' > "$STATUS.tmp" && mv "$STATUS.tmp" "$STATUS"
}

CODIGOS=(); ARQS=(); declare -A ORIGEM=()

lista_db="$(php "$APP_DIR/bin/ambientes.php" listar 2>&1)"
if [[ $? -eq 0 ]]; then
    while IFS= read -r s; do
        [[ "$s" =~ $CODIGO_RE ]] || continue
        ORIGEM[$s]="db"
        [[ -n "$FILTRO" && "$s" != "$FILTRO" ]] && continue
        CODIGOS+=("$s"); ARQS+=("db")
    done <<< "$lista_db"
else
    echo "Aviso: não foi possível ler os ambientes do banco: $lista_db" >> "$LOG"
fi

if compgen -G "$AMB_DIR/*.env" > /dev/null; then
    for f in "$AMB_DIR"/*.env; do
        s="$(basename "$f" .env)"
        if [[ ! "$s" =~ $CODIGO_RE ]]; then
            echo "Ignorando $f: nome do arquivo deve usar só minúsculas, números, _ e -" >> "$LOG"
            continue
        fi
        [[ -n "${ORIGEM[$s]}" ]] && continue
        ORIGEM[$s]="arquivo"
        [[ -n "$FILTRO" && "$s" != "$FILTRO" ]] && continue
        CODIGOS+=("$s"); ARQS+=("$f")
    done
elif [[ ${#ORIGEM[@]} -eq 0 && -r "$LEGADO_ENV" ]]; then
    if [[ -z "$FILTRO" || "$FILTRO" == "padrao" ]]; then
        CODIGOS+=("padrao"); ARQS+=("$LEGADO_ENV"); ORIGEM[padrao]="arquivo"
    fi
fi

TOTAL=${#CODIGOS[@]}
if [[ $TOTAL -eq 0 ]]; then
    if [[ -n "$FILTRO" ]]; then
        grava_status "erro" "Ambiente '$FILTRO' não encontrado ou está inativo"
    else
        grava_status "erro" "Nenhum ambiente ativo. Cadastre um na tela Ambientes."
    fi
    exit 1
fi

cd "$DATA_DIR" || exit 1

resultado() {
    jq -nc --arg s "$1" --arg n "$2" --arg e "$3" --arg m "$4" \
        '{codigo: $s, nome: $n, estado: $e, mensagem: $m}' >> "$RES"
    if [[ "${ORIGEM[$1]}" == "db" ]]; then
        php "$APP_DIR/bin/ambientes.php" resultado "$1" "$3" "$4" >> "$LOG" 2>&1
    fi
}

i=0
for idx in "${!CODIGOS[@]}"; do
    codigo="${CODIGOS[$idx]}"; arq="${ARQS[$idx]}"; i=$((i + 1))

    if [[ "$arq" == "db" ]]; then
        ENVTMP="$(mktemp)"; chmod 600 "$ENVTMP"
        if ! php "$APP_DIR/bin/ambientes.php" env "$codigo" > "$ENVTMP" 2>> "$LOG"; then
            rm -f "$ENVTMP"; ENVTMP=""
            resultado "$codigo" "$codigo" "erro" "Não foi possível ler as credenciais do ambiente (chave_cifra config.php ou BD)."
            continue
        fi
        arq="$ENVTMP"
    elif [[ ! -r "$arq" ]]; then
        resultado "$codigo" "$codigo" "erro" "Sem permissão para ler $arq (ajuste permissao www-data)"
        echo "[$codigo] sem permissão para ler $arq" >> "$LOG"
        continue
    fi

    nome="$( ( source "$arq" >/dev/null 2>&1; printf '%s' "${AMBIENTE_NOME:-}" ) )"
    rotulo="${nome:-$codigo}"

    grava_status "executando" "[$i/$TOTAL] $rotulo: consultando a API Proxmox"
    echo "=== [$codigo] Coleta iniciada em $(date -Iseconds) ===" >> "$LOG"

    rm -f "$DATA_DIR/$codigo.json"
    saida_coleta="$(PVE_ENV_FILE="$arq" CSV_FILE="$codigo.csv" "$APP_DIR/bin/consulta-api-proxmox.sh" 2>&1)"
    rc=$?
    echo "$saida_coleta" >> "$LOG"
    if [[ -n "$ENVTMP" ]]; then rm -f "$ENVTMP"; ENVTMP=""; fi

    if [[ $rc -eq 4 ]]; then
        resultado "$codigo" "$rotulo" "inacessivel" "Ambiente Inacessível "
        continue
    elif [[ $rc -eq 5 ]]; then
        motivo="$(printf '%s\n' "$saida_coleta" | grep '^ERRO:' | tail -n 1 | sed 's/^ERRO: *//')"
        resultado "$codigo" "$rotulo" "erro" "${motivo:-Permissões ou credenciais do Proxmox recusadas.}"
        continue
    elif [[ $rc -ne 0 ]]; then
        resultado "$codigo" "$rotulo" "erro" "Falha ao consultar o Proxmox (código $rc)."
        continue
    fi

    grava_status "executando" "[$i/$TOTAL] $rotulo: importando dados no banco"
    saida="$(php "$APP_DIR/bin/importar.php" "$DATA_DIR/$codigo.json" "$codigo" "$nome" 2>&1)"
    rc=$?
    echo "$saida" >> "$LOG"
    if [[ $rc -ne 0 ]]; then
        resultado "$codigo" "$rotulo" "erro" "Falha na importação: $saida"
    else
        resultado "$codigo" "$rotulo" "ok" "$saida"
    fi
done

FINAL="$(jq -s --argjson total "$TOTAL" '
    . as $r
    | ($r | map(select(.estado != "ok"))) as $f
    | ($r | map(select(.estado == "ok"))) as $o
    | def txt(l): l | map(if $total > 1 then "\(.nome): \(.mensagem)" else .mensagem end) | join(" | ");
      if ($f | length) == 0 then {estado: "ok", mensagem: txt($o)}
      elif ($o | length) == 0 and ($f | map(.estado) | unique) == ["inacessivel"] then {estado: "inacessivel", mensagem: txt($f)}
      elif ($o | length) == 0 then {estado: "erro", mensagem: txt($f)}
      else {estado: "parcial", mensagem: txt($f)} end
' "$RES")"
grava_status "$(jq -r .estado <<< "$FINAL")" "$(jq -r .mensagem <<< "$FINAL")"

[[ "$(jq -r .estado <<< "$FINAL")" == "ok" ]] && exit 0
exit 1
