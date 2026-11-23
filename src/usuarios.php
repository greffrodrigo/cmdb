<?php
declare(strict_types=1);

require_once __DIR__ . '/ambientes.php';

const PERFIS_VALIDOS = ['ADMIN', 'ANALISTA'];
const SENHA_MINIMA = 10;

function usuarios_listar(PDO $pdo): array
{
    return $pdo->query('SELECT id, nome, login, perfil, ativo, criado_em FROM usuario ORDER BY ativo DESC, nome')->fetchAll();
}

function usuario_obter(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, nome, login, perfil, ativo, criado_em FROM usuario WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function admins_ativos(PDO $pdo, ?int $exceto = null): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuario WHERE perfil = 'ADMIN' AND ativo = 1 AND id <> ?");
    $st->execute([$exceto ?? 0]);
    return (int)$st->fetchColumn();
}

function usuario_erro_senha(string $senha): ?string
{
    return comprimento($senha) < SENHA_MINIMA ? 'A senha precisa ter pelo menos ' . SENHA_MINIMA . ' caracteres.' : null;
}

function usuario_criar(PDO $pdo, string $nome, string $login, string $senha, string $perfil): array
{
    $nome = trim($nome);
    $login = trim($login);
    if ($nome === '' || comprimento($nome) > 120) {
        return [false, 'Informe o nome (até 120 caracteres).'];
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $login)) {
        return [false, 'Login inválido: 3 a 60 caracteres, só letras, números, ponto, hífen e sublinhado.'];
    }
    if (!in_array($perfil, PERFIS_VALIDOS, true)) {
        return [false, 'Perfil inválido.'];
    }
    if ($e = usuario_erro_senha($senha)) {
        return [false, $e];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM usuario WHERE login = ?');
    $st->execute([$login]);
    if ((int)$st->fetchColumn() > 0) {
        return [false, 'Já existe um usuário com este login.'];
    }
    try {
        $pdo->prepare('INSERT INTO usuario (nome, login, senha_hash, perfil, ativo) VALUES (?,?,?,?,1)')
            ->execute([$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    } catch (PDOException) {
        return [false, 'Já existe um usuário com este login.'];
    }
    return [true, "Usuário '$login' cadastrado."];
}

function usuario_atualizar(PDO $pdo, int $id, string $nome, string $perfil, bool $ativo, ?string $novaSenha, int $idLogado): array
{
    $atual = usuario_obter($pdo, $id);
    if (!$atual) {
        return [false, 'Usuário não encontrado.'];
    }
    $nome = trim($nome);
    if ($nome === '' || comprimento($nome) > 120) {
        return [false, 'Informe o nome (até 120 caracteres).'];
    }
    if (!in_array($perfil, PERFIS_VALIDOS, true)) {
        return [false, 'Perfil inválido.'];
    }
    if ($novaSenha !== null && $novaSenha !== '' && ($e = usuario_erro_senha($novaSenha))) {
        return [false, $e];
    }
    if ($id === $idLogado && (!$ativo || $perfil !== 'ADMIN')) {
        return [false, 'Você não pode desativar nem rebaixar o seu próprio usuário. Peça a outro administrador.'];
    }
    if (!($perfil === 'ADMIN' && $ativo) && admins_ativos($pdo, $id) === 0) {
        return [false, 'Precisa existir pelo menos um administrador ativo.'];
    }
    if ($novaSenha !== null && $novaSenha !== '') {
        $pdo->prepare('UPDATE usuario SET nome = ?, perfil = ?, ativo = ?, senha_hash = ? WHERE id = ?')
            ->execute([$nome, $perfil, $ativo ? 1 : 0, password_hash($novaSenha, PASSWORD_DEFAULT), $id]);
    } else {
        $pdo->prepare('UPDATE usuario SET nome = ?, perfil = ?, ativo = ? WHERE id = ?')
            ->execute([$nome, $perfil, $ativo ? 1 : 0, $id]);
    }
    return [true, 'Usuário atualizado.'];
}

function usuario_trocar_senha(PDO $pdo, int $id, string $atual, string $nova): array
{
    $st = $pdo->prepare('SELECT senha_hash FROM usuario WHERE id = ? AND ativo = 1');
    $st->execute([$id]);
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($atual, (string)$hash)) {
        return [false, 'A senha atual está incorreta.'];
    }
    if ($e = usuario_erro_senha($nova)) {
        return [false, $e];
    }
    $pdo->prepare('UPDATE usuario SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
    return [true, 'Senha alterada.'];
}
