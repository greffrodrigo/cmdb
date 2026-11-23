<?php
declare(strict_types=1);
require __DIR__ . '/../src/auth.php';

header('Content-Type: application/json; charset=utf-8');

$u = usuario_atual();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensagem' => 'Não autenticado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensagem' => 'Requisição inválida']);
    exit;
}

$dataDir = cfg()['data_dir'];
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0750, true);
}

$fp = @fopen($dataDir . '/coleta.lock', 'c');
if ($fp && !flock($fp, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => true, 'mensagem' => 'Já existe uma coleta em andamento']);
    exit;
}
if ($fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
}

$script = realpath(__DIR__ . '/../bin/coletar.sh');
if (!$script) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensagem' => 'coletar.sh não encontrado']);
    exit;
}

$amb = trim((string)($_POST['ambiente'] ?? ''));
if ($amb !== '' && !preg_match(CODIGO_RE, $amb)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'mensagem' => 'Ambiente inválido']);
    exit;
}

exec('setsid nohup ' . escapeshellarg($script) . ($amb !== '' ? ' ' . escapeshellarg($amb) : '') . ' > /dev/null 2>&1 &');
echo json_encode(['ok' => true, 'mensagem' => 'Coleta iniciada', 'iniciado_em' => date('c')]);
