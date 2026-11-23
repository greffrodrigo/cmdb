<?php
declare(strict_types=1);
require __DIR__ . '/../src/auth.php';

sessao();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $login = trim((string)($_POST['login'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');
        $st = db()->prepare('SELECT id, nome, login, senha_hash, perfil, ativo FROM usuario WHERE login = ?');
        $st->execute([$login]);
        $u = $st->fetch();
        $hash = $u['senha_hash'] ?? '$2y$10$usesomesillystringforsalt.invalidinvalidinvalidinvalidinv';
        if ($u && (int)$u['ativo'] === 1 && password_verify($senha, $hash)) {
            session_regenerate_id(true);
            $_SESSION['usuario'] = ['id' => (int)$u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil']];
            header('Location: index.php');
            exit;
        }
        $erro = 'Login ou senha inválidos.';
        usleep(400000);
    }
}
$titulo = cfg()['site_title'] ?? 'CMDB';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar - <?= h($titulo) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" autocomplete="off">
    <h1><?= h($titulo) ?></h1>
    <?php if ($erro): ?><div class="alert"><?= h($erro) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Login <input name="login" required autofocus></label>
    <label>Senha <input name="senha" type="password" required></label>
    <button class="btn btn-primary" type="submit">Entrar</button>
  </form>
</body>
</html>
