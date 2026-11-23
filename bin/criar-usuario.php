<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/db.php';

$login  = $argv[1] ?? '';
$nome   = $argv[2] ?? '';
$perfil = strtoupper($argv[3] ?? 'ADMIN');
if ($login === '' || $nome === '' || !in_array($perfil, ['ADMIN', 'ANALISTA'], true)) {
    fwrite(STDERR, "Uso: php criar-usuario.php <login> \"<Nome>\" [ADMIN|ANALISTA]\n");
    exit(1);
}

fwrite(STDOUT, 'Senha (min. 10 caracteres): ');
system('stty -echo');
$senha = trim((string)fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");
if (strlen($senha) < 10) {
    fwrite(STDERR, "Senha muito curta.\n");
    exit(1);
}

$hash = password_hash($senha, PASSWORD_DEFAULT);
$st = db()->prepare(
    'INSERT INTO usuario (nome, login, senha_hash, perfil, ativo) VALUES (?,?,?,?,1)
     ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash), perfil = VALUES(perfil), ativo = 1'
);
$st->execute([$nome, $login, $hash, $perfil]);
echo "Usuário '$login' ($perfil) gravado.\n";
