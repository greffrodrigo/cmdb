<?php
declare(strict_types=1);
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/ambientes.php';

$user = exigir_admin();
$pdo  = db();
sessao();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$amb = ambiente_obter($pdo, $id);
if (!$amb) {
    flash('erro', 'Ambiente não encontrado.');
    redirecionar('ambientes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf('ambiente-editar.php?id=' . $id);
    if (!chave_cifra_ok()) {
        flash('erro', "Defina 'chave_cifra' no config.php antes de editar ambientes.");
        redirecionar('ambiente-editar.php?id=' . $id);
    }
    [$ok, $msg] = ambiente_atualizar($pdo, $id, $_POST, chave_cifra());
    flash($ok ? 'ok' : 'erro', $msg);
    if (!$ok) {
        $_SESSION['old'] = array_diff_key($_POST, ['token' => 1, 'csrf' => 1]);
    }
    redirecionar($ok ? 'ambientes.php' : 'ambiente-editar.php?id=' . $id);
}

$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);
$v = fn(string $k) => $old[$k] ?? $amb[$k];
$ativo = $old ? !empty($old['ativo']) : (bool)$amb['ativo'];

cabecalho('Editar ambiente', $user, 'ambientes');
?>
<p><a href="ambientes.php">&larr; Voltar</a></p>
<h1 class="page-title">Editar ambiente <span class="iconf"><?= h($amb['codigo']) ?></span></h1>

<article class="card pad">
  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$amb['id'] ?>">
    <label>Nome do ambiente
      <input name="nome" required maxlength="120" value="<?= h((string)$v('nome')) ?>">
    </label>
    <label>Endereço (IP ou nome DNS)
      <input name="host" required value="<?= h((string)$v('host')) ?>">
    </label>
    <label>Porta da API
      <input name="porta" type="number" min="1" max="65535" value="<?= h((string)$v('porta')) ?>">
    </label>
    <label>Usuário da API (usuário!token)
      <input name="usuario_api" required value="<?= h((string)$v('usuario_api')) ?>">
    </label>
    <label>Segredo do token
      <input name="token" type="password" autocomplete="new-password">
    </label>
    <label>Ordem da coleta
      <input name="ordem" type="number" value="<?= h((string)$v('ordem')) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="ativo" value="1" <?= $ativo ? 'checked' : '' ?>>
      Ativo (incluído nas coletas)
    </label>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Salvar</button>
      <a class="btn" href="ambientes.php">Cancelar</a>
    </div>
  </form>
</article>
<?php rodape();
