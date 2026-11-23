<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function sessao(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('cmdb');
        session_start();
    }
}

function usuario_atual(): ?array
{
    static $cache = false;
    sessao();
    $id = (int)($_SESSION['usuario']['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if ($cache !== false && ($cache['id'] ?? 0) === $id) {
        return $cache;
    }
    $st = db()->prepare('SELECT id, nome, perfil FROM usuario WHERE id = ? AND ativo = 1');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        unset($_SESSION['usuario']);
        return $cache = null;
    }
    $_SESSION['usuario'] = ['id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'perfil' => (string)$u['perfil']];
    return $cache = $_SESSION['usuario'];
}

function exigir_login(): array
{
    $u = usuario_atual();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $t): bool
{
    sessao();
    return $t !== null && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
