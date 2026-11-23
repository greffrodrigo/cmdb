<?php
declare(strict_types=1);
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/usuarios.php';

$user = exigir_admin();
$pdo  = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$alvo = usuario_obter($pdo, $id);
if (!$alvo) {
    flash('erro', 'Usuário não encontrado.');
    redirecionar('usuarios.php');
}
$proprio = (int)$alvo['id'] === (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf('usuario-editar.php?id=' . $id);
    $senha = (string)($_POST['senha'] ?? '');
    if ($senha !== (string)($_POST['senha2'] ?? '')) {
        [$ok, $msg] = [false, 'A confirmação da nova senha não confere.'];
    } else {
        [$ok, $msg] = usuario_atualizar(
            $pdo,
            $id,
            (string)($_POST['nome'] ?? ''),
            (string)($_POST['perfil'] ?? ''),
            !empty($_POST['ativo']),
            $senha,
            (int)$user['id']
        );
    }
    flash($ok ? 'ok' : 'erro', $msg);
    redirecionar($ok ? 'usuarios.php' : 'usuario-editar.php?id=' . $id);
}

cabecalho('Editar usuário', $user, 'usuarios');
?>
<p><a href="usuarios.php">&larr; Voltar</a></p>
<h1 class="page-title">Editar usuário <span class="iconf"><?= h($alvo['login']) ?></span></h1>

<article class="card pad">
  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= (int)$alvo['id'] ?>">
    <label>Login
      <input value="<?= h($alvo['login']) ?>" disabled>
    </label>
    <label>Nome completo
      <input name="nome" required maxlength="120" value="<?= h($alvo['nome']) ?>">
    </label>
    <label>Perfil
      <select name="perfil">
        <option value="ANALISTA" <?= $alvo['perfil'] === 'ANALISTA' ? 'selected' : '' ?>>ANALISTA: consulta, coleta e CSV</option>
        <option value="ADMIN" <?= $alvo['perfil'] === 'ADMIN' ? 'selected' : '' ?>>ADMIN: consulta, atualiza dados e administra</option>
      </select>
    </label>
    <label class="check">
      <input type="checkbox" name="ativo" value="1" <?= $alvo['ativo'] ? 'checked' : '' ?>>
      Ativo
    </label>
    <label>Nova senha
      <input name="senha" type="password" minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
    </label>
    <label>Confirmar a nova senha
      <input name="senha2" type="password" minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
    </label>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Salvar</button>
      <a class="btn" href="usuarios.php">Cancelar</a>
    </div>
  </form>
  <?php if ($proprio): ?>
  <?php endif; ?>
</article>
<?php rodape();
