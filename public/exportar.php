<?php
declare(strict_types=1);
require __DIR__ . '/../src/auth.php';

$user = exigir_login();
$pdo  = db();

$id = (int)($_GET['cluster'] ?? 0);
$st = $pdo->prepare("SELECT id, iconf, nome FROM iconf_item WHERE id = ? AND tipo = 'CLUSTER'");
$st->execute([$id]);
$cluster = $st->fetch();
if (!$cluster) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Cluster não encontrado.';
    exit;
}

$todos = [(int)$cluster['id'] => ['id' => (int)$cluster['id'], 'iconf' => $cluster['iconf'], 'tipo' => 'CLUSTER',
    'chave_origem' => '', 'nome' => $cluster['nome'], 'pai_id' => null, 'ativo' => 1]];
$st = $pdo->prepare('SELECT id, iconf, tipo, chave_origem, nome, pai_id, ativo FROM iconf_item WHERE pai_id = ?');
$st->execute([$id]);
$nodes = $st->fetchAll();
$guests = [];
foreach ($nodes as $n) {
    $st->execute([(int)$n['id']]);
    foreach ($st->fetchAll() as $g) {
        $guests[(int)$n['id']][] = $g;
    }
}
$a = $pdo->prepare('SELECT ativo FROM iconf_item WHERE id = ?');
$a->execute([$id]);
$todos[$id]['ativo'] = (int)$a->fetchColumn();

$ids = [$id];
foreach ($nodes as $n) {
    $ids[] = (int)$n['id'];
}
foreach ($guests as $lista) {
    foreach ($lista as $g) {
        $ids[] = (int)$g['id'];
    }
}
$attr = [];
$in = implode(',', array_map('intval', $ids));
foreach ($pdo->query("SELECT item_id, campo, valor FROM iconf_atributo WHERE item_id IN ($in)")->fetchAll() as $r) {
    $attr[(int)$r['item_id']][$r['campo']] = $r['valor'];
}

function cel(?string $v, bool $numero = false): string
{
    $v = (string)$v;
    if ($numero && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return str_replace('.', ',', $v);
    }
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) {
        return "'" . $v;
    }
    return $v;
}

$linha = function (array $it, string $clusterNome, string $nodeNome) use ($attr): array {
    $x = $attr[(int)$it['id']] ?? [];
    $guest = in_array($it['tipo'], ['VM', 'CT'], true);
    return [
        cel($it['iconf']), cel($it['tipo']), cel($it['nome']), $guest ? cel($it['chave_origem']) : '',
        cel($clusterNome), cel($nodeNome), $it['ativo'] ? 'Sim' : 'Não',
        cel($x['status'] ?? ''), cel($x['vcpus'] ?? '', true), cel($x['memory_gb'] ?? '', true), cel($x['disk_gb'] ?? '', true),
        cel($x['network'] ?? ''), cel($x['snapshots'] ?? ''), cel($x['days_stopped'] ?? ''),
        cel($x['pve_version'] ?? ''), cel($x['cpu_cores'] ?? ''), cel($x['memory_total_gb'] ?? '', true),
    ];
};

$codigo = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$cluster['nome']) ?? '');
$codigo = trim($codigo, '-') ?: 'cluster';
$arquivo = 'cmdb-' . $codigo . '-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$sep = ';';
fputcsv($out, ['ICONF', 'Tipo', 'Nome', 'ID (VMID)', 'Cluster', 'Node', 'Ativo', 'Status', 'vCPU', 'Memória (GB)',
    'Disco (GB)', 'Rede', 'Snapshots', 'Dias desligada', 'Versão PVE', 'Cores', 'RAM total (GB)'], $sep, '"', '');
fputcsv($out, $linha($todos[$id], (string)$cluster['nome'], ''), $sep, '"', '');
usort($nodes, fn($x, $y) => strnatcasecmp((string)$x['nome'], (string)$y['nome']));
foreach ($nodes as $n) {
    fputcsv($out, $linha($n, (string)$cluster['nome'], (string)$n['nome']), $sep, '"', '');
    $lista = $guests[(int)$n['id']] ?? [];
    usort($lista, fn($x, $y) => (int)$x['chave_origem'] <=> (int)$y['chave_origem']);
    foreach ($lista as $g) {
        fputcsv($out, $linha($g, (string)$cluster['nome'], (string)$n['nome']), $sep, '"', '');
    }
}
fclose($out);
