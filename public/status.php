<?php
declare(strict_types=1);
require __DIR__ . '/../src/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!usuario_atual()) {
    http_response_code(401);
    echo json_encode(['estado' => 'erro', 'mensagem' => 'Não autenticado']);
    exit;
}

$dir = cfg()['data_dir'];
$estado = ['estado' => 'ocioso', 'mensagem' => ''];

$f = $dir . '/status.json';
if (is_readable($f)) {
    $j = json_decode((string)file_get_contents($f), true);
    if (is_array($j)) {
        $estado = $j;
    }
}

if (($estado['estado'] ?? '') === 'executando') {
    $fp = @fopen($dir . '/coleta.lock', 'c');
    if ($fp) {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $estado = ['estado' => 'erro', 'mensagem' => 'Coleta interrompida antes de terminar'];
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

echo json_encode($estado, JSON_UNESCAPED_UNICODE);
