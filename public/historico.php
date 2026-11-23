<?php
declare(strict_types=1);
require __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/layout.php';

$user = exigir_login();
$pdo  = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, p.nome AS pai_nome, p.iconf AS pai_iconf FROM iconf_item i LEFT JOIN iconf_item p ON p.id = i.pai_id WHERE i.id = ?');
$st->execute([$id]);
$item = $st->fetch();
if (!$item) {
    http_response_code(404);
    echo 'Item não encontrado.';
    exit;
}

$st = $pdo->prepare('SELECT campo, valor, atualizado_em FROM iconf_atributo WHERE item_id = ? ORDER BY campo');
$st->execute([$id]);
$atuais = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT h.id, h.coleta_id, h.campo, h.valor_anterior, h.valor_novo, h.alterado_por, h.registrado_em
       FROM iconf_historico h WHERE h.item_id = ? ORDER BY h.id DESC LIMIT 500'
);
$st->execute([$id]);
$hist = $st->fetchAll();

$rotulos = [
    'item' => 'Item', 'nome' => 'Nome', 'ativo' => 'Ativo', 'node' => 'Node', 'status' => 'Status',
    'vcpus' => 'vCPU', 'memory_gb' => 'Memória (GB)', 'disk_gb' => 'Disco (GB)', 'network' => 'Rede',
    'snapshots' => 'Snapshots', 'days_stopped' => 'Dias desligada', 'pve_version' => 'Versão PVE',
    'cpu_cores' => 'Cores', 'memory_total_gb' => 'RAM total (GB)', 'disk_wearout' => 'Desgaste dos discos',
    'storage_free' => 'Storage livre',
];
$rot = fn(string $c) => $rotulos[$c] ?? $c;
cabecalho('Histórico ' . $item['iconf'], $user, 'cmdb');
?>
  <p><a href="index.php">&larr; Voltar</a></p>

  <article class="card pad">
    <h2>
      <span class="tag tag-<?= strtolower($item['tipo'] === 'CLUSTER' ? 'cluster' : ($item['tipo'] === 'NODE' ? 'node' : $item['tipo'])) ?>"><?= h($item['tipo']) ?></span>
      <?= h($item['nome']) ?> <span class="iconf"><?= h($item['iconf']) ?></span>
      <?php if (!$item['ativo']): ?><span class="chip chip-gray">INATIVO</span><?php endif; ?>
    </h2>
    <p class="muted">
      <?php if ($item['pai_nome']): ?>Vinculado a: <a href="historico.php?id=<?= (int)$item['pai_id'] ?>"><?= h($item['pai_nome']) ?></a> (<?= h($item['pai_iconf']) ?>) · <?php endif; ?>
      Criado em <?= h(data_br($item['criado_em'])) ?>
    </p>

    <h3>Valores atuais</h3>
    <div class="kv">
      <?php foreach ($atuais as $a): ?>
        <div><span class="k"><?= h($rot($a['campo'])) ?></span><span class="v"><?= h($a['valor'] ?? '-') ?></span></div>
      <?php endforeach; ?>
    </div>
  </article>

  <article class="card">
    <div class="pad"><h3>Histórico de alterações</h3></div>
    <div class="table-wrap">
    <table>
      <thead><tr><th>Data</th><th>Campo</th><th>Valor anterior</th><th>Novo valor</th><th>Alterado por</th><th>Coleta</th></tr></thead>
      <tbody>
      <?php if (!$hist): ?>
        <tr><td colspan="6" class="muted center">Sem registros.</td></tr>
      <?php endif; ?>
      <?php foreach ($hist as $r): ?>
        <tr>
          <td><?= h(data_br($r['registrado_em'])) ?></td>
          <td><?= h($rot($r['campo'])) ?></td>
          <td class="muted"><?= h($r['valor_anterior'] ?? '-') ?></td>
          <td><strong><?= h($r['valor_novo'] ?? '-') ?></strong></td>
          <td class="muted"><?= h($r['alterado_por'] ?? (in_array($r['campo'], CAMPOS_RECURSO, true) && $r['valor_anterior'] !== null ? 'não identificado' : '-')) ?></td>
          <td class="muted">#<?= (int)$r['coleta_id'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if (count($hist) >= 500): ?><p class="muted pad">Exibindo os 500 registros mais recentes.</p><?php endif; ?>
  </article>
<?php rodape();
