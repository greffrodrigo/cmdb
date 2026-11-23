<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/coletor.php';

$arquivo = $argv[1] ?? '';
$codigo    = $argv[2] ?? '';
$nome    = ($argv[3] ?? '') !== '' ? $argv[3] : null;
if ($arquivo === '' || $codigo === '' || !is_readable($arquivo)) {
    fwrite(STDERR, "Uso: php importar.php <arquivo.json> <codigo-do-ambiente> [\"Nome do cluster\"]\n");
    exit(1);
}
if (!preg_match(CODIGO_RE, $codigo)) {
    fwrite(STDERR, "Código inválido (use letras minúsculas, números, _ e -).\n");
    exit(1);
}

try {
    $json = json_decode((string)file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
    $r = (new Importador(db()))->importar($json, $codigo, $nome);
    printf(
        "Coleta #%d importada: %d lidos, %d criados, %d alterados, %d inativados\n",
        $r['coleta_id'], $r['lidos'], $r['criados'], $r['alterados'], $r['inativados']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro na importação: ' . $e->getMessage() . "\n");
    exit(2);
}
