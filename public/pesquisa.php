<?php
declare(strict_types=1);
require __DIR__ . '/../src/layout.php';

$user = exigir_login();
$pdo  = db();
$q = trim((string)($_GET['q'] ?? ''));

$itens = [];
foreach ($pdo->query('SELECT id, iconf, tipo, chave_origem, nome, pai_id, ativo FROM iconf_item')->fetchAll() as $r) {
    $itens[(int)$r['id']] = $r;
}

$achados = [];
$exato = null;
if ($q !== '') {
    $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $sql = "SELECT id FROM iconf_item WHERE iconf LIKE ? ESCAPE '!' OR nome LIKE ? ESCAPE '!' OR chave_origem LIKE ? ESCAPE '!' ORDER BY id LIMIT 200";
    $st = $pdo->prepare($sql);
    $st->execute([$like, $like, $like]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if (preg_match('/^(?:iconf-?)?0*(\d{1,9})$/i', $q, $m)) {
        $alvo = sprintf('ICONF-%06d', (int)$m[1]);
        foreach ($itens as $it) {
            if ($it['iconf'] === $alvo) {
                $exato = (int)$it['id'];
                array_unshift($ids, $exato);
                $ids = array_values(array_unique($ids));
            }
        }
    }
    if ($exato !== null && count($ids) === 1 && preg_match('/^iconf/i', $q)) {
        redirecionar('historico.php?id=' . $exato);
    }
    foreach ($ids as $id) {
        if (isset($itens[$id])) {
            $achados[] = $itens[$id];
        }
    }
}

function caminho(array $it, array $itens): array
{
    $node = $cluster = null;
    $p = $it['pai_id'] !== null ? ($itens[(int)$it['pai_id']] ?? null) : null;
    if ($it['tipo'] === 'NODE') {
        $cluster = $p;
    } elseif ($p) {
        $node = $p;
        $cluster = $p['pai_id'] !== null ? ($itens[(int)$p['pai_id']] ?? null) : null;
    }
    return [$cluster, $node];
}

cabecalho('Pesquisa', $user, 'cmdb');
?>
<p><a href="index.php">&larr; Voltar</a></p>
<h1 class="page-title">Pesquisa de ICONF</h1>
<article class="card pad">
  <form method="get" action="pesquisa.php" class="busca busca-grande">
    <input type="search" name="q" value="<?= h($q) ?>" autofocus>
    <button class="btn btn-primary" type="submit">Pesquisar</button>
  </form>
</article>

<?php if ($q !== ''): ?>
<article class="card">
  <div class="table-wrap">
  <table>
    <thead><tr><th>ICONF</th><th>TIPO</th><th>NOME</th><th>ID</th><th>CLUSTER</th><th>NODE</th><th>SITUAÇÃO</th><th></th></tr></thead>
    <tbody>
    <?php if (!$achados): ?>
      <tr><td colspan="8" class="muted center">Nenhum item encontrado para "<?= h($q) ?>".</td></tr>
    <?php endif; ?>
    <?php foreach ($achados as $it): [$cl, $nd] = caminho($it, $itens); ?>
      <tr class="<?= $it['ativo'] ? '' : 'inativo' ?>">
        <td><a class="iconf" href="historico.php?id=<?= (int)$it['id'] ?>"><?= h($it['iconf']) ?></a></td>
        <td><span class="tag tag-<?= h(strtolower((string)$it['tipo'])) ?>"><?= h($it['tipo']) ?></span></td>
        <td><strong><?= h($it['nome']) ?></strong></td>
        <td class="muted"><?= in_array($it['tipo'], ['VM', 'CT'], true) ? '#' . h($it['chave_origem']) : '-' ?></td>
        <td><?= $cl ? h($cl['nome']) . ' <span class="muted">' . h((string)$cl['iconf']) . '</span>' : '-' ?></td>
        <td><?= $nd ? h($nd['nome']) : '-' ?></td>
        <td><span class="badge <?= $it['ativo'] ? 'b-ok' : 'b-off' ?>"><?= $it['ativo'] ? 'ATIVO' : 'INATIVO' ?></span></td>
        <td class="acoes"><a class="btn btn-sm" href="historico.php?id=<?= (int)$it['id'] ?>">Histórico</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</article>
<?php if (count($achados) >= 200): ?><p class="muted note">Mostrando os 200 primeiros resultados; refine a pesquisa.</p><?php endif; ?>
<?php endif; ?>
<?php rodape();
