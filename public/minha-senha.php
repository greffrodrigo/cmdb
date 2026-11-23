<?php
declare(strict_types=1);
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/usuarios.php';

$user = exigir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf('minha-senha.php');
    $nova = (string)($_POST['nova'] ?? '');
    if ($nova !== (string)($_POST['nova2'] ?? '')) {
        [$ok, $msg] = [false, 'A confirmação da nova senha não confere.'];
    } else {
        [$ok, $msg] = usuario_trocar_senha(db(), (int)$user['id'], (string)($_POST['atual'] ?? ''), $nova);
    }
    flash($ok ? 'ok' : 'erro', $msg);
    redirecionar('minha-senha.php');
}

cabecalho('Alterar senha', $user, '');
?>
<p><a href="index.php">&larr; Voltar</a></p>
<h1 class="page-title">Alterar minha senha</h1>
<article class="card pad narrow">
  <form method="post" class="form-grid one" autocomplete="off">
    <?= csrf_campo() ?>
    <label>Senha atual
      <input name="atual" type="password" required autocomplete="current-password">
    </label>
    <label>Nova senha
      <input name="nova" type="password" required minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
    </label>
    <label>Confirmar a nova senha
      <input name="nova2" type="password" required minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Alterar senha</button></div>
  </form>
</article>
<?php rodape();
