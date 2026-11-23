<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function redirecionar(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $tipo, string $msg): void
{
    sessao();
    $_SESSION['flash'][] = [$tipo, $msg];
}

function flash_html(): string
{
    sessao();
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$tipo, $msg]) {
        $out .= '<div class="alert ' . ($tipo === 'ok' ? 'ok' : ($tipo === 'info' ? 'info' : '')) . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function exigir_post_csrf(string $voltarPara): void
{
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        flash('erro', 'Sessão expirada. Tente novamente.');
        redirecionar($voltarPara);
    }
}

function exigir_admin(): array
{
    $u = exigir_login();
    if (($u['perfil'] ?? '') !== 'ADMIN') {
        http_response_code(403);
        cabecalho('Acesso restrito', $u, '');
        echo '<div class="alert">Esta página é restrita a administradores.</div>';
        rodape();
        exit;
    }
    return $u;
}

function nav_html(array $user, string $ativo): string
{
    $item = fn(string $chave, string $href, string $rotulo): string =>
        '<a class="nav-link' . ($chave === $ativo ? ' ativo' : '') . '" href="' . $href . '">' . h($rotulo) . '</a>';
    $html = '<nav class="nav">' . $item('cmdb', 'index.php', 'CMDB');
    if (($user['perfil'] ?? '') === 'ADMIN') {
        $html .= $item('ambientes', 'ambientes.php', 'Ambientes') . $item('usuarios', 'usuarios.php', 'Usuários');
    }
    if ($ativo === 'cmdb' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php') {
        $html .= '<form class="busca" action="pesquisa.php" method="get" role="search">'
            . '<input type="search" name="q" aria-label="Pesquisar ICONF" maxlength="60" value="' . h((string)($_GET['q'] ?? '')) . '">'
            . '<button class="btn btn-sm" type="submit">&#128269; Pesquisar</button></form>';
    }
    return $html . '</nav>';
}

function cabecalho(string $titulo, array $user, string $ativo): void
{
    $site = cfg()['site_title'] ?? 'CMDB';
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($titulo) ?> - <?= h($site) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="logo">&#9638;</span> <?= h($site) ?></div>
  <?= nav_html($user, $ativo) ?>
  <div class="userbox">
    <div><strong><?= h($user['nome'] ?? '') ?></strong><br><small class="muted"><?= h($user['perfil'] ?? '') ?></small></div>
    <a class="btn btn-sm" href="minha-senha.php">Alterar senha</a>
    <a class="btn btn-danger-outline" href="logout.php">Sair</a>
  </div>
</header>
<main class="container">
<?= flash_html() ?>
<?php
}

function rodape(): void
{
    echo "</main>\n</body>\n</html>\n";
}
