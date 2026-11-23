<?php
declare(strict_types=1);
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/usuarios.php';

$user = exigir_admin();
$pdo  = db();
sessao();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf('usuarios.php');
    $nome   = (string)($_POST['nome'] ?? '');
    $login  = (string)($_POST['login'] ?? '');
    $perfil = (string)($_POST['perfil'] ?? 'ANALISTA');
    $senha  = (string)($_POST['senha'] ?? '');
    if ($senha !== (string)($_POST['senha2'] ?? '')) {
        [$ok, $msg] = [false, 'A confirmação da senha não confere.'];
    } else {
        [$ok, $msg] = usuario_criar($pdo, $nome, $login, $senha, $perfil);
    }
    flash($ok ? 'ok' : 'erro', $msg);
    if (!$ok) {
        $_SESSION['old'] = ['nome' => $nome, 'login' => $login, 'perfil' => $perfil];
    }
    redirecionar('usuarios.php');
}

$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);
$lista = usuarios_listar($pdo);

cabecalho('Usuários', $user, 'usuarios');
?>
<h1 class="page-title">Usuários</h1>

<article class="card">
  <div class="table-wrap">
  <table>
    <thead><tr><th>NOME</th><th>LOGIN</th><th>PERFIL</th><th>SITUAÇÃO</th><th>CRIADO EM</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $u): ?>
      <tr class="<?= $u['ativo'] ? '' : 'inativo' ?>">
        <td><strong><?= h($u['nome']) ?></strong><?= (int)$u['id'] === (int)$user['id'] ? ' <span class="chip chip-blue">você</span>' : '' ?></td>
        <td><span class="iconf"><?= h($u['login']) ?></span></td>
        <td><span class="badge <?= $u['perfil'] === 'ADMIN' ? 'b-dark' : 'b-gray' ?>"><?= h($u['perfil']) ?></span></td>
        <td><span class="badge <?= $u['ativo'] ? 'b-ok' : 'b-off' ?>"><?= $u['ativo'] ? 'ATIVO' : 'INATIVO' ?></span></td>
        <td class="muted"><?= h(data_br($u['criado_em'], false)) ?></td>
        <td><a class="btn btn-sm" href="usuario-editar.php?id=<?= (int)$u['id'] ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</article>

<article class="card pad">
  <h2 class="sec-title">Novo usuário</h2>
  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_campo() ?>
    <label>Nome completo
      <input name="nome" required maxlength="120" value="<?= h($old['nome'] ?? '') ?>">
    </label>
    <label>Login
      <input name="login" required minlength="3" maxlength="60" pattern="[A-Za-z0-9._\-]+" value="<?= h($old['login'] ?? '') ?>">
    </label>
    <label>Perfil
      <select name="perfil">
        <option value="ANALISTA" <?= ($old['perfil'] ?? '') === 'ANALISTA' ? 'selected' : '' ?>>ANALISTA: Coleta, Pesquisa e Exporta</option>
        <option value="ADMIN" <?= ($old['perfil'] ?? '') === 'ADMIN' ? 'selected' : '' ?>>ADMIN: Permissões totais</option>
      </select>
    </label>
    <label>Senha
      <input name="senha" type="password" required minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
    </label>
    <label>Confirmar a senha
      <input name="senha2" type="password" required minlength="<?= SENHA_MINIMA ?>" autocomplete="new-password">
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Cadastrar usuário</button></div>
  </form>
</article>
<?php rodape();
