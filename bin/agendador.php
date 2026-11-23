<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/agendamento.php';

$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . "] $m\n");

try {
    $pdo = db();
    $agora = new DateTime('now');
    $a = agendamento_obter($pdo);
    agendamento_pulso($pdo, $agora);
    $slot = agendamento_slot_atual((string)$a['modo'], (string)$a['hora'], $agora);
    if ($slot === null) {
        exit(0);
    }
    $anterior = $a['ultimo_slot'];
    if ($anterior !== null && new DateTime((string)$anterior) >= $slot) {
        exit(0);
    }
    $slotTxt = $slot->format(AGENDA_FMT);
    if (!agendamento_reservar($pdo, $slotTxt)) {
        exit(0);
    }

    $script = getenv('CMDB_COLETAR') ?: __DIR__ . '/coletar.sh';
    $log("Horário $slotTxt: iniciando coleta ({$a['modo']})");
    exec(escapeshellarg($script) . ' >/dev/null 2>&1', $saida, $rc);

    if ($rc === 3) {
        agendamento_devolver($pdo, $slotTxt, $anterior !== null ? (string)$anterior : null);
        $log('Coleta em andamento; nova tentativa no próximo minuto.');
        exit(0);
    }

    $estado = $rc === 0 ? 'ok' : 'erro';
    $msg = $rc === 0 ? 'Coleta concluída' : "Coleta terminou com código $rc";
    $arq = rtrim((string)(cfg()['data_dir'] ?? ''), '/') . '/status.json';
    if (is_readable($arq) && ($st = json_decode((string)file_get_contents($arq), true)) && isset($st['estado'])) {
        $estado = (string)$st['estado'];
        $msg = (string)($st['mensagem'] ?? $msg);
    }
    agendamento_resultado($pdo, new DateTime('now'), $estado, $msg);
    $log("Resultado: $estado - $msg");
    exit($estado === 'ok' ? 0 : 1);
} catch (Throwable $e) {
    $log('Erro: ' . $e->getMessage());
    exit(2);
}
