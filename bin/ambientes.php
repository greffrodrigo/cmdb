<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/ambientes.php';

$cmd = $argv[1] ?? '';
try {
    $pdo = db();
    switch ($cmd) {
        case 'listar':
            foreach (ambientes_listar($pdo, true) as $a) {
                echo $a['codigo'], "\n";
            }
            break;
        case 'env':
            $codigo = $argv[2] ?? '';
            if (!preg_match(CODIGO_RE, $codigo)) {
                throw new InvalidArgumentException('Código inválido.');
            }
            echo ambiente_env_texto($pdo, $codigo, chave_cifra());
            break;
        case 'resultado':
            $codigo = $argv[2] ?? '';
            if (!preg_match(CODIGO_RE, $codigo)) {
                throw new InvalidArgumentException('Código inválido.');
            }
            ambiente_registrar_resultado($pdo, $codigo, (string)($argv[3] ?? 'erro'), (string)($argv[4] ?? ''));
            break;
        default:
            fwrite(STDERR, "Uso: php ambientes.php listar | env <codigo> | resultado <codigo> <estado> <mensagem>\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(2);
}
