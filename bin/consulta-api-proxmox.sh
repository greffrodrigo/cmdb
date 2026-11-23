#!/bin/bash
PVE_ENV_FILE="${PVE_ENV_FILE:-/etc/cmdb/proxmox.env}"
if [[ -r "$PVE_ENV_FILE" ]]; then
    source "$PVE_ENV_FILE"
fi
if [[ -z "$PVE_HOST" || -z "$PVE_USER" || -z "$PVE_TOKEN" ]]; then
    echo "ERRO: Defina PVE_HOST, PVE_USER e PVE_TOKEN (ver $PVE_ENV_FILE)" >&2
    exit 1
fi

API_URL="https://$PVE_HOST:${PVE_PORT:-8006}/api2/json"
CSV_FILE="${CSV_FILE:-coletado.csv}"
CONNECT_TIMEOUT="${CONNECT_TIMEOUT:-60}"
PRECHECK_MAX_TIME="${PRECHECK_MAX_TIME:-60}"
CALL_MAX_TIME="${CALL_MAX_TIME:-300}"

trap 'echo "ERRO: Ambiente Inacessível para coleta" >&2; exit 4' USR1
trap 'exit 5' USR2

precheck_code=$(curl -s -k -o /dev/null -w '%{http_code}' \
    --connect-timeout "$CONNECT_TIMEOUT" --max-time "$PRECHECK_MAX_TIME" \
    -H "Authorization: PVEAPIToken=$PVE_USER=$PVE_TOKEN" "$API_URL/version")
precheck_rc=$?
if [[ $precheck_rc -ne 0 ]]; then
    echo "ERRO: Ambiente Inacessível para coleta (sem resposta em ${PRECHECK_MAX_TIME}s, curl código $precheck_rc)" >&2
    exit 4
fi
if [[ "$precheck_code" == "401" || "$precheck_code" == "403" ]]; then
    echo "ERRO: Credenciais do Proxmox recusadas (HTTP $precheck_code)" >&2
    exit 5
fi

PERMS_NEEDED='["Sys.Audit","VM.Audit","Datastore.Audit"]'
if [[ "${SKIP_PERM_CHECK:-0}" != "1" ]]; then
    perm_json=$(curl -s -k --connect-timeout "$CONNECT_TIMEOUT" --max-time "$PRECHECK_MAX_TIME" \
        -H "Authorization: PVEAPIToken=$PVE_USER=$PVE_TOKEN" "$API_URL/access/permissions")
    if [[ $? -ne 0 ]]; then
        echo "ERRO: Ambiente inacessível para coleta (sem resposta em ${PRECHECK_MAX_TIME}s ao consultar permissões)" >&2
        exit 4
    fi
    if echo "$perm_json" | jq -e '.data | type == "object"' >/dev/null 2>&1; then
        perm_missing=$(echo "$perm_json" | jq -r --argjson need "$PERMS_NEEDED" \
            '$need - [.data["/"] // {} | keys[]] | join(", ")')
        if [[ -n "$perm_missing" ]]; then
            echo "ERRO: Falha de permissão: o token $PVE_USER não tem $perm_missing em '/'. Configure como PVEAuditor em / e 'Propagate' no token permission e user permission." >&2
            exit 5
        fi
    else
        echo "AVISO: Não foi possível checar permissões do token." >&2
    fi
fi

api_get() {
    local endpoint=$1 out rc code body
    out=$(curl -s -k --connect-timeout "$CONNECT_TIMEOUT" --max-time "$CALL_MAX_TIME" \
        -w $'\n%{http_code}' -H "Authorization: PVEAPIToken=$PVE_USER=$PVE_TOKEN" "$API_URL/$endpoint")
    rc=$?
    if [[ $rc -ne 0 ]]; then
        echo "ERRO: Falha de conexão em $endpoint (curl código $rc)" >&2
        kill -USR1 $$
        return $rc
    fi
    code="${out##*$'\n'}"
    body="${out%$'\n'*}"
    if [[ "$code" == "401" || "$code" == "403" ]]; then
        if [[ "$endpoint" =~ ^nodes$|^nodes/[^/]+/(qemu|lxc)$|^nodes/[^/]+/(qemu|lxc)/[0-9]+/(config|status/current)$ ]]; then
            echo "ERRO: Permissão negada (HTTP $code) em $endpoint. Checar permissões de token no Proxmox." >&2
            kill -USR2 $$
            return 1
        fi
        echo "AVISO: Permissão negada (HTTP $code) em $endpoint; dado opcional ignorado." >&2
    fi
    printf '%s' "$body"
}

convert_to_gb() {
    local raw_size="$1"
    if [[ "$raw_size" == *T ]]; then
        awk -v t="${raw_size%T}" 'BEGIN {printf "%.2f", t * 1024}'
    elif [[ "$raw_size" == *G ]]; then
        echo "${raw_size%G}"
    elif [[ "$raw_size" == *M ]]; then
        awk -v m="${raw_size%M}" 'BEGIN {printf "%.2f", m / 1024}'
    elif [[ "$raw_size" =~ ^[0-9]+$ ]]; then
        awk -v b="$raw_size" 'BEGIN {printf "%.2f", b / 1073741824}'
    else
        echo "0"
    fi
}

get_net_status() {
    local config="$1"
    local vm_state="$2"
    local result=""
    local nets net key val st

    mapfile -t nets < <(echo "$config" | jq -r '.data | to_entries[] | select(.key | test("^net[0-9]+$")) | "\(.key)|\(.value)"')

    if [[ ${#nets[@]} -eq 0 ]]; then
        echo "none"
        return
    fi

    for net in "${nets[@]}"; do
        key="${net%%|*}"
        val="${net#*|}"

        if [[ "$val" == *link_down=1* ]]; then
            st="down"
        elif [[ "$vm_state" == "running" ]]; then
            st="up"
        else
            st="stopped"
        fi

        result+="${result:+;}${key}:${st}"
    done

    echo "$result"
}

get_stopped_days() {
    local node="$1" type="$2" id="$3" state="$4"
    local stop_re start_re tasks last_stop last_start last_ts rrd now

    if [[ "$state" != "stopped" ]]; then
        echo ""
        return
    fi

    if [[ "$type" == "qemu" ]]; then
        stop_re="^(qmstop|qmshutdown)$"
        start_re="^(qmstart|qmresume)$"
    else
        stop_re="^(vzstop|vzshutdown)$"
        start_re="^vzstart$"
    fi

    now=$(date +%s)
    tasks=$(api_get "nodes/$node/tasks?vmid=$id&limit=0&source=all")

    last_stop=$(echo "$tasks" | jq -r --arg re "$stop_re" '[.data[]? | select(.type | test($re)) | .endtime // empty] | max // empty')
    last_start=$(echo "$tasks" | jq -r --arg re "$start_re" '[.data[]? | select(.type | test($re)) | .endtime // empty] | max // empty')

    last_ts=""
    if [[ -n "$last_stop" ]] && { [[ -z "$last_start" ]] || (( last_stop >= last_start )); }; then
        last_ts="$last_stop"
    else
        rrd=$(api_get "nodes/$node/$type/$id/rrddata?timeframe=year")
        last_ts=$(echo "$rrd" | jq -r '[.data[]? | select(.cpu != null) | .time] | max // empty')
    fi

    if [[ -z "$last_ts" ]]; then
        echo "N/A"
    else
        echo $(( (now - last_ts) / 86400 ))
    fi
}

get_node_version() {
    local node="$1"
    local v
    v=$(api_get "nodes/$node/version" | jq -r '.data.version // empty')
    echo "${v:-N/A}"
}

get_cluster_name() {
    api_get "cluster/status" | jq -r '[.data[]? | select(.type == "cluster") | .name][0] // empty'
}

get_node_hw() {
    local node="$1"
    local st
    st=$(api_get "nodes/$node/status")
    NODE_CORES=$(echo "$st" | jq -r '.data.cpuinfo.cpus // empty')
    NODE_RAM_GB=$(echo "$st" | jq -r 'if .data.memory.total then ((.data.memory.total / 1073741824 * 100 | round) / 100) else empty end')
    NODE_CORES="${NODE_CORES:-N/A}"
    NODE_RAM_GB="${NODE_RAM_GB:-N/A}"
}

get_snapshot_count() {
    local node="$1" type="$2" id="$3"
    local n
    n=$(api_get "nodes/$node/$type/$id/snapshot" | jq -r '[.data[]? | select(.name != "current")] | length')
    echo "${n:-0}"
}

declare -A SEEN_DISK
get_node_wearout() {
    local node="$1"
    local data dev wwn serial wear key out="" total=0 skipped=0

    data=$(api_get "nodes/$node/disks/list" | jq -r '.data[]? | "\(.devpath | split("/") | last)|\(.wwn // "")|\(.serial // "")|\(.wearout // "N/A")"')

    while IFS='|' read -r dev wwn serial wear; do
        [[ -z "$dev" ]] && continue
        total=$((total + 1))

        if [[ "$wear" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
            wear=$(awk -v w="$wear" 'BEGIN {printf "%d%%", 100 - w}')
        fi

        key=""
        if [[ -n "$wwn" && "$wwn" != "unknown" ]]; then
            key="$wwn"
        elif [[ -n "$serial" ]]; then
            key="$serial"
        fi

        if [[ -n "$key" ]]; then
            if [[ -n "${SEEN_DISK[$key]}" ]]; then
                skipped=$((skipped + 1))
                continue
            fi
            SEEN_DISK[$key]=1
        fi

        out+="${out:+;}${dev}:${wear}"
    done <<< "$data"

    if [[ $total -eq 0 ]]; then
        WEAROUT_RESULT="N/A"
    elif [[ -z "$out" ]]; then
        WEAROUT_RESULT="-"
    else
        WEAROUT_RESULT="$out"
    fi
}

declare -A SEEN_STORAGE
get_node_storage_free() {
    local node="$1"
    local data st shared free out="" total=0

    data=$(api_get "nodes/$node/storage" | jq -r '.data[]? | select(.avail != null) | "\(.storage)|\(.shared // 0)|\(((.avail / 1073741824 * 100 | round) / 100))"')

    while IFS='|' read -r st shared free; do
        [[ -z "$st" ]] && continue
        total=$((total + 1))

        if [[ "$shared" == "1" ]]; then
            [[ -n "${SEEN_STORAGE[$st]}" ]] && continue
            SEEN_STORAGE[$st]=1
        fi

        out+="${out:+;}${st}:${free}GB"
    done <<< "$data"

    if [[ $total -eq 0 ]]; then
        STORAGE_FREE_RESULT="N/A"
    elif [[ -z "$out" ]]; then
        STORAGE_FREE_RESULT="-"
    else
        STORAGE_FREE_RESULT="$out"
    fi
}

JSON_FILE="${CSV_FILE%.csv}.json"
JSON_NODES_TMP=$(mktemp)
JSON_GUESTS_TMP=$(mktemp)
trap 'rm -f "$JSON_NODES_TMP" "$JSON_GUESTS_TMP"' EXIT

JQ_PARSERS='
def net_list: if . == "" or . == "none" then [] else split(";") | map(split(":") | {interface: .[0], status: .[1]}) end;
def wear_list: if . == "" or . == "N/A" or . == "-" then [] else split(";") | map(split(":") | {disk: .[0], wearout_pct: (.[1] | rtrimstr("%") | try tonumber catch null)}) end;
def storage_list: if . == "" or . == "N/A" or . == "-" then [] else split(";") | map(split(":") | {storage: .[0], free_gb: (.[1] | rtrimstr("GB") | try tonumber catch null)}) end;
'

json_add_node() {
    jq -nc --arg node "$1" --arg ver "$2" --arg wear "$3" --arg stor "$4" --arg cores "$5" --arg ram "${6//,/.}" \
        "$JQ_PARSERS"'{node: $node, pve_version: $ver, cpu_cores: ($cores | try tonumber catch null), memory_total_gb: ($ram | try tonumber catch null), disk_wearout: ($wear | wear_list), storage_free: ($stor | storage_list)}' \
        >> "$JSON_NODES_TMP"
}

get_config_tasks() {
    local node="$1" id="$2" t a b
    t=$(api_get "nodes/$node/tasks?vmid=$id&limit=100&source=all")
    a=$(echo "$t" | jq -c '[.data[]? | select((.type // "") | test("^(qmconfig|qmresize|vzconfig|vzresize|resize|qmmove|move_volume|qmrollback|vzrollback)$")) | {type: .type, user: .user, starttime: .starttime}]' 2>/dev/null) || a='[]'
    [[ -n "$a" ]] || a='[]'
    b=$(echo "$CLUSTER_LOG" | jq -c --arg id "$id" '[.data[]? | select((.msg // "") | test("^update (VM|CT) " + $id + ":")) | {type: "log", user: .user, starttime: .time}]' 2>/dev/null) || b='[]'
    [[ -n "$b" ]] || b='[]'
    jq -nc --argjson a "$a" --argjson b "$b" '($a + $b) | sort_by(.starttime) | reverse | .[0:40]' 2>/dev/null || echo '[]'
}

json_add_guest() {
    jq -nc --arg type "$1" --arg node "$2" --arg id "$3" --arg name "$4" --arg state "$5" \
        --arg mem "${6//,/.}" --arg disk "${7//,/.}" --arg vcpus "$8" --arg net "$9" --arg days "${10}" --arg snaps "${11}" --argjson tasks "${12:-[]}" \
        "$JQ_PARSERS"'{type: $type, node: $node, id: ($id | tonumber), name: $name, status: $state, memory_gb: ($mem | tonumber), disk_gb: ($disk | tonumber), vcpus: ($vcpus | try tonumber catch $vcpus), network: ($net | net_list), days_stopped: ($days | try tonumber catch null), snapshots: ($snaps | try tonumber catch null), config_tasks: $tasks}' \
        >> "$JSON_GUESTS_TMP"
}

echo "TYPE,NODE,ID,NAME,STATUS,MEMORY_GB,DISK_GB,VCPUS,NET_STATUS,DAYS_STOPPED,PVE_VERSION,DISK_WEAROUT,STORAGE_FREE" > "$CSV_FILE"

CLUSTER_NAME=$(get_cluster_name)
nodes=$(api_get "nodes" | jq -r '.data[].node')

CLUSTER_LOG=$(api_get "cluster/log?max=2000")
[[ "$CLUSTER_LOG" == \{* ]] || CLUSTER_LOG='{}'

if [[ -z "$nodes" ]]; then
    echo "ERRO: Nenhum node retornado pela API (Revisar dados e permissões)" >&2
    exit 2
fi

for NODE in $nodes; do
    pve_version=$(get_node_version "$NODE")
    get_node_hw "$NODE"
    get_node_wearout "$NODE";       disk_wearout="$WEAROUT_RESULT"
    get_node_storage_free "$NODE";  storage_free="$STORAGE_FREE_RESULT"
    json_add_node "$NODE" "$pve_version" "$disk_wearout" "$storage_free" "$NODE_CORES" "$NODE_RAM_GB"

    vms=$(api_get "nodes/$NODE/qemu" | jq -r '.data[].vmid')
    for VMID in $vms; do
        config=$(api_get "nodes/$NODE/qemu/$VMID/config")
        status=$(api_get "nodes/$NODE/qemu/$VMID/status/current")

        name=$(echo "$config" | jq -r '.data.name // "N/A"')
        memory_mb=$(echo "$config" | jq -r '.data.memory // 0')
        memory_gb=$(awk -v m="$memory_mb" 'BEGIN {printf "%.2f", m / 1024}')
        state=$(echo "$status" | jq -r '.data.status // "unknown"')

        cores=$(echo "$config" | jq -r '.data.cores // 1')
        sockets=$(echo "$config" | jq -r '.data.sockets // 1')
        explicit_vcpus=$(echo "$config" | jq -r '.data.vcpus // empty')

        if [[ -n "$explicit_vcpus" ]]; then
            vcpus="$explicit_vcpus"
        else
            vcpus=$((cores * sockets))
        fi

        mapfile -t disks < <(echo "$config" | jq -r '.data | to_entries[] | select(.key | test("^(scsi|virtio|sata|ide)[0-9]+$")) | .value')
        total_gb=0
        for disk in "${disks[@]}"; do
            raw_size=$(echo "$disk" | grep -oP 'size=\K[0-9]+[TGM]?' || echo 0)
            size_gb=$(convert_to_gb "$raw_size")
            total_gb=$(awk -v a="$total_gb" -v b="$size_gb" 'BEGIN {printf "%.2f", a + b}')
        done

        net_status=$(get_net_status "$config" "$state")

        days_stopped=$(get_stopped_days "$NODE" "qemu" "$VMID" "$state")
        snapshots=$(get_snapshot_count "$NODE" "qemu" "$VMID")
        json_add_guest "VM" "$NODE" "$VMID" "$name" "$state" "$memory_gb" "$total_gb" "$vcpus" "$net_status" "$days_stopped" "$snapshots" "$(get_config_tasks "$NODE" "$VMID")"

        echo "VM,$NODE,$VMID,\"$name\",$state,$memory_gb,$total_gb,$vcpus,\"$net_status\",$days_stopped,\"$pve_version\",\"$disk_wearout\",\"$storage_free\"" >> "$CSV_FILE"
    done

    cts=$(api_get "nodes/$NODE/lxc" | jq -r '.data[].vmid')
    for CTID in $cts; do
        config=$(api_get "nodes/$NODE/lxc/$CTID/config")
        status=$(api_get "nodes/$NODE/lxc/$CTID/status/current")

        name=$(echo "$config" | jq -r '.data.hostname // "N/A"')
        memory_mb=$(echo "$config" | jq -r '.data.memory // 0')
        memory_gb=$(awk -v m="$memory_mb" 'BEGIN {printf "%.2f", m / 1024}')
        state=$(echo "$status" | jq -r '.data.status // "unknown"')

        cpus=$(echo "$config" | jq -r '.data.cores // "N/A"')
        
    mapfile -t disks < <(echo "$config" | jq -r '.data | to_entries[] | select(.key | test("^(rootfs|mp[0-9]+)$")) | .value')
        total_gb=0
        for disk in "${disks[@]}"; do
            raw_size=$(echo "$disk" | grep -oP 'size=\K[0-9]+[TGM]?' || echo 0)
            size_gb=$(convert_to_gb "$raw_size")
            total_gb=$(awk -v a="$total_gb" -v b="$size_gb" 'BEGIN {printf "%.2f", a + b}')
        done

        net_status=$(get_net_status "$config" "$state")

        days_stopped=$(get_stopped_days "$NODE" "lxc" "$CTID" "$state")
        snapshots=$(get_snapshot_count "$NODE" "lxc" "$CTID")
        json_add_guest "CT" "$NODE" "$CTID" "$name" "$state" "$memory_gb" "$total_gb" "$cpus" "$net_status" "$days_stopped" "$snapshots" "$(get_config_tasks "$NODE" "$CTID")"

        echo "CT,$NODE,$CTID,\"$name\",$state,$memory_gb,$total_gb,$cpus,\"$net_status\",$days_stopped,\"$pve_version\",\"$disk_wearout\",\"$storage_free\"" >> "$CSV_FILE"
    done
done

jq -n --slurpfile nodes "$JSON_NODES_TMP" --slurpfile guests "$JSON_GUESTS_TMP" --arg ts "$(date -Iseconds)" --arg cl "$CLUSTER_NAME" '
{
  generated_at: $ts,
  cluster: {name: (if $cl == "" then null else $cl end)},
  nodes: ($nodes | map(. as $n | . + {guests: ($guests | map(select(.node == $n.node) | del(.node)))}))
}' > "$JSON_FILE"

echo "OK: Relatório gerado com sucesso: $CSV_FILE"
echo "OK: JSON gerado com sucesso: $JSON_FILE"
