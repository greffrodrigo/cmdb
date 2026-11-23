<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const CAMPOS_HISTORICO_NODE  = ['pve_version', 'cpu_cores', 'memory_total_gb', 'disk_wearout'];
const CAMPOS_HISTORICO_GUEST = ['node', 'status', 'vcpus', 'memory_gb', 'disk_gb', 'network', 'snapshots'];
const CAMPOS_VOLATEIS_NODE   = ['storage_free'];
const CAMPOS_VOLATEIS_GUEST  = ['days_stopped'];

function norm(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
        $s = number_format((float)$v, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string)$v;
}

function fmt_network(mixed $net): ?string
{
    if (!is_array($net) || !$net) {
        return 'none';
    }
    $p = [];
    foreach ($net as $n) {
        $p[] = ($n['interface'] ?? '?') . ':' . ($n['status'] ?? '?');
    }
    return implode(';', $p);
}

function fmt_wearout(mixed $w): ?string
{
    if (!is_array($w) || !$w) {
        return null;
    }
    $p = [];
    foreach ($w as $d) {
        $pct = $d['wearout_pct'] ?? null;
        $p[] = ($d['disk'] ?? '?') . ':' . ($pct === null ? 'N/A' : $pct . '%');
    }
    return implode(';', $p);
}

function fmt_storage(mixed $s): ?string
{
    if (!is_array($s) || !$s) {
        return null;
    }
    $p = [];
    foreach ($s as $d) {
        $p[] = ($d['storage'] ?? '?') . ':' . norm($d['free_gb'] ?? null) . 'GB';
    }
    return implode(';', $p);
}

final class Importador
{
    private PDO $pdo;
    private int $coletaId = 0;
    private int $clusterId = 0;
    private ?int $janelaDesde = null;
    private array $tarefasAtuais = [];
    public int $criados = 0;
    public int $alterados = 0;
    public int $inativados = 0;
    public int $lidos = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function importar(array $json, string $codigo, ?string $clusterNomeFixo = null): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            throw new InvalidArgumentException('Código do ambiente não informado.');
        }
        $this->criados = $this->alterados = $this->inativados = $this->lidos = 0;

        if (!isset($json['nodes']) || !is_array($json['nodes']) || count($json['nodes']) === 0) {
            throw new InvalidArgumentException('JSON inválido ou sem nodes: importação abortada para não inativar itens por engano.');
        }

        $geradaEm = null;
        if (!empty($json['generated_at'])) {
            try {
                $geradaEm = (new DateTime((string)$json['generated_at']))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $geradaEm = null;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO coleta (gerada_em) VALUES (?)')->execute([$geradaEm]);
            $this->coletaId = (int)$this->pdo->lastInsertId();

            $vistos = [];
            $nodesFalha = [];

            $nomeApi = trim((string)($json['cluster']['name'] ?? ''));
            $nomeFixo = trim((string)$clusterNomeFixo);
            $nomeConhecido = $nomeFixo !== '' ? $nomeFixo : $nomeApi;
            $clusterId = $this->garantirCluster($codigo, $nomeConhecido);
            $this->clusterId = $clusterId;
            $this->janelaDesde = $this->inicioJanela($clusterId);
            $this->pdo->prepare('UPDATE coleta SET cluster_id = ? WHERE id = ?')->execute([$clusterId, $this->coletaId]);
            $vistos[$clusterId] = true;
            $this->lidos++;

            foreach ($json['nodes'] as $n) {
                $nodeNome = (string)($n['node'] ?? '');
                if ($nodeNome === '') {
                    continue;
                }
                $nodeId = $this->garantirItem('NODE', $nodeNome, $nodeNome, $clusterId);
                $vistos[$nodeId] = true;
                $this->lidos++;

                $falhou = (($n['pve_version'] ?? 'N/A') === 'N/A');
                if ($falhou) {
                    $nodesFalha[$nodeId] = true;
                    continue;
                }

                $this->gravarAtributos($nodeId, [
                    'pve_version'     => norm($n['pve_version'] ?? null),
                    'cpu_cores'       => norm($n['cpu_cores'] ?? null),
                    'memory_total_gb' => norm($n['memory_total_gb'] ?? null),
                    'disk_wearout'    => fmt_wearout($n['disk_wearout'] ?? null),
                    'storage_free'    => fmt_storage($n['storage_free'] ?? null),
                ], CAMPOS_HISTORICO_NODE);

                foreach (($n['guests'] ?? []) as $g) {
                    if (!isset($g['id'])) {
                        continue;
                    }
                    $tipo = (($g['type'] ?? 'VM') === 'CT') ? 'CT' : 'VM';
                    $nome = (string)($g['name'] ?? ('guest-' . $g['id']));
                    $gid  = $this->garantirItem($tipo, (string)(int)$g['id'], $nome, $nodeId);
                    $vistos[$gid] = true;
                    $this->lidos++;

                    $this->tarefasAtuais = is_array($g['config_tasks'] ?? null) ? $g['config_tasks'] : [];
                    $this->gravarAtributos($gid, [
                        'node'         => $nodeNome,
                        'status'       => norm($g['status'] ?? null),
                        'vcpus'        => norm($g['vcpus'] ?? null),
                        'memory_gb'    => norm($g['memory_gb'] ?? null),
                        'disk_gb'      => norm($g['disk_gb'] ?? null),
                        'network'      => fmt_network($g['network'] ?? null),
                        'snapshots'    => norm($g['snapshots'] ?? null),
                        'days_stopped' => norm($g['days_stopped'] ?? null),
                    ], CAMPOS_HISTORICO_GUEST);
                    $this->tarefasAtuais = [];
                }
            }

            $st = $this->pdo->prepare(
                "SELECT i.id, i.pai_id, i.ativo FROM iconf_item i
                  WHERE (i.tipo = 'NODE' AND i.pai_id = ?)
                     OR (i.tipo IN ('VM','CT') AND i.pai_id IN
                           (SELECT n.id FROM iconf_item n WHERE n.tipo = 'NODE' AND n.pai_id = ?))"
            );
            $st->execute([$clusterId, $clusterId]);
            $rows = $st->fetchAll();
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                if (isset($vistos[$id])) {
                    continue;
                }
                if ($r['pai_id'] !== null && isset($nodesFalha[(int)$r['pai_id']])) {
                    continue;
                }
                if ((int)$r['ativo'] === 1) {
                    $this->pdo->prepare('UPDATE iconf_item SET ativo = 0, ultima_coleta_id = ultima_coleta_id WHERE id = ?')
                        ->execute([$id]);
                    $this->historico($id, 'ativo', '1', '0');
                    $this->inativados++;
                }
            }

            if ($vistos) {
                $ids = implode(',', array_map('intval', array_keys($vistos)));
                $this->pdo->exec("UPDATE iconf_item SET ultima_coleta_id = {$this->coletaId} WHERE id IN ($ids)");
            }
            $this->pdo->prepare(
                'UPDATE coleta SET itens_criados=?, itens_alterados=?, itens_inativados=?, itens_lidos=? WHERE id=?'
            )->execute([$this->criados, $this->alterados, $this->inativados, $this->lidos, $this->coletaId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'coleta_id'  => $this->coletaId,
            'criados'    => $this->criados,
            'alterados'  => $this->alterados,
            'inativados' => $this->inativados,
            'lidos'      => $this->lidos,
        ];
    }

    private function garantirCluster(string $codigo, string $nomeConhecido): int
    {
        $st = $this->pdo->prepare("SELECT * FROM iconf_item WHERE tipo = 'CLUSTER' AND chave_origem = ? LIMIT 1");
        $st->execute([$codigo]);
        $item = $st->fetch();
        if (!$item) {
            $nome = $nomeConhecido !== '' ? $nomeConhecido : $codigo;
            return $this->garantirItem('CLUSTER', $codigo, $nome, null);
        }

        $id = (int)$item['id'];
        $mudou = false;
        if ($nomeConhecido !== '' && $item['nome'] !== $nomeConhecido) {
            $this->pdo->prepare('UPDATE iconf_item SET nome = ? WHERE id = ?')->execute([$nomeConhecido, $id]);
            $this->historico($id, 'nome', $item['nome'], $nomeConhecido);
            $mudou = true;
        }
        if ((int)$item['ativo'] === 0) {
            $this->pdo->prepare('UPDATE iconf_item SET ativo = 1 WHERE id = ?')->execute([$id]);
            $this->historico($id, 'ativo', '0', '1');
            $mudou = true;
        }
        if ($mudou) {
            $this->alterados++;
        }
        return $id;
    }

    private function garantirItem(string $tipo, string $chave, string $nome, ?int $paiId): int
    {
        if ($tipo === 'VM' || $tipo === 'CT') {
            $st = $this->pdo->prepare(
                "SELECT g.* FROM iconf_item g JOIN iconf_item n ON n.id = g.pai_id
                  WHERE g.tipo IN ('VM','CT') AND g.chave_origem = ? AND n.pai_id = ? LIMIT 1"
            );
            $st->execute([$chave, $this->clusterId]);
        } elseif ($tipo === 'NODE') {
            $st = $this->pdo->prepare("SELECT * FROM iconf_item WHERE tipo = 'NODE' AND chave_origem = ? AND pai_id <=> ? LIMIT 1");
            $st->execute([$chave, $paiId]);
        } else {
            $st = $this->pdo->prepare("SELECT * FROM iconf_item WHERE tipo = 'CLUSTER' AND chave_origem = ? LIMIT 1");
            $st->execute([$chave]);
        }
        $item = $st->fetch();

        if (!$item) {
            $this->pdo->prepare(
                'INSERT INTO iconf_item (tipo, chave_origem, nome, pai_id, ativo, ultima_coleta_id) VALUES (?,?,?,?,1,?)'
            )->execute([$tipo, $chave, $nome, $paiId, $this->coletaId]);
            $id = (int)$this->pdo->lastInsertId();
            $iconf = sprintf('ICONF-%06d', $id);
            $this->pdo->prepare('UPDATE iconf_item SET iconf = ? WHERE id = ?')->execute([$iconf, $id]);
            $this->historico($id, 'item', null, "criado ($tipo) $iconf");
            $this->criados++;
            return $id;
        }

        $id = (int)$item['id'];
        $mudou = false;

        if ($item['nome'] !== $nome) {
            $this->pdo->prepare('UPDATE iconf_item SET nome = ? WHERE id = ?')->execute([$nome, $id]);
            $this->historico($id, 'nome', $item['nome'], $nome);
            $mudou = true;
        }
        if (($tipo === 'VM' || $tipo === 'CT') && (int)$item['pai_id'] !== (int)$paiId) {
            $this->pdo->prepare('UPDATE iconf_item SET pai_id = ? WHERE id = ?')->execute([$paiId, $id]);
            $mudou = true;
        }
        if ((int)$item['ativo'] === 0) {
            $this->pdo->prepare('UPDATE iconf_item SET ativo = 1 WHERE id = ?')->execute([$id]);
            $this->historico($id, 'ativo', '0', '1');
            $mudou = true;
        }
        if ($mudou) {
            $this->alterados++;
        }
        return $id;
    }

    private function gravarAtributos(int $itemId, array $novos, array $historizar): void
    {
        $st = $this->pdo->prepare('SELECT campo, valor FROM iconf_atributo WHERE item_id = ?');
        $st->execute([$itemId]);
        $atuais = [];
        foreach ($st->fetchAll() as $r) {
            $atuais[$r['campo']] = $r['valor'];
        }

        $mudouAlgo = false;
        foreach ($novos as $campo => $valor) {
            $existe = array_key_exists($campo, $atuais);
            if (!$existe) {
                $this->pdo->prepare('INSERT INTO iconf_atributo (item_id, campo, valor) VALUES (?,?,?)')
                    ->execute([$itemId, $campo, $valor]);
                if (in_array($campo, $historizar, true) && $valor !== null) {
                    $this->historico($itemId, $campo, null, $valor);
                }
                continue;
            }
            if ($atuais[$campo] === $valor) {
                continue;
            }
            $this->pdo->prepare('UPDATE iconf_atributo SET valor = ? WHERE item_id = ? AND campo = ?')
                ->execute([$valor, $itemId, $campo]);
            if (in_array($campo, $historizar, true)) {
                $this->historico($itemId, $campo, $atuais[$campo], $valor);
                $mudouAlgo = true;
            }
        }
        if ($mudouAlgo) {
            $this->alterados++;
        }
    }

    private function historico(int $itemId, string $campo, ?string $ant, ?string $novo): void
    {
        $autor = in_array($campo, CAMPOS_RECURSO, true) && $ant !== null
            ? $this->autorDaAlteracao() : null;
        $this->pdo->prepare(
            'INSERT INTO iconf_historico (item_id, coleta_id, campo, valor_anterior, valor_novo, alterado_por) VALUES (?,?,?,?,?,?)'
        )->execute([$itemId, $this->coletaId, $campo, $ant, $novo, $autor]);
    }

    private function inicioJanela(int $clusterId): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT gerada_em FROM coleta WHERE cluster_id = ? AND id < ? AND gerada_em IS NOT NULL ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$clusterId, $this->coletaId]);
        $v = $st->fetchColumn();
        return $v ? (new DateTime((string)$v))->getTimestamp() : null;
    }

    private function autorDaAlteracao(): ?string
    {
        if ($this->janelaDesde === null) {
            return null;
        }
        $usuarios = [];
        foreach ($this->tarefasAtuais as $t) {
            $ts = (int)($t['starttime'] ?? 0);
            $u = trim((string)($t['user'] ?? ''));
            if ($u !== '' && $ts >= $this->janelaDesde - 120) {
                $usuarios[$u] = true;
            }
        }
        $lista = array_keys($usuarios);
        if (!$lista) {
            return null;
        }
        $txt = count($lista) === 1 ? $lista[0] : implode(' ou ', $lista) . ' (provável)';
        return function_exists('mb_substr') ? mb_substr($txt, 0, 190, 'UTF-8') : substr($txt, 0, 190);
    }
}
