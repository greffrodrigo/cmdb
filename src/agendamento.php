<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const AGENDA_MODOS = ['desligado', '30min', 'diario'];
const AGENDA_FMT = 'Y-m-d H:i:s';
const HORA_RE = '/^([01]\d|2[0-3]):([0-5]\d)$/';

function agendamento_obter(PDO $pdo): array
{
    $sql = 'SELECT modo, hora, ultimo_slot, ultima_execucao, ultimo_estado, ultima_mensagem, ultimo_pulso FROM agendamento WHERE id = 1';
    $r = $pdo->query($sql)->fetch();
    if (!$r) {
        $pdo->exec("INSERT INTO agendamento (id, modo, hora) VALUES (1, 'desligado', '02:00')");
        $r = $pdo->query($sql)->fetch();
    }
    return $r;
}

function agendamento_slot_atual(string $modo, string $hora, DateTime $agora): ?DateTime
{
    if ($modo === '30min') {
        $s = clone $agora;
        $s->setTime((int)$s->format('H'), ((int)$s->format('i') >= 30) ? 30 : 0, 0);
        return $s;
    }
    if ($modo === 'diario' && preg_match(HORA_RE, $hora, $m)) {
        $s = clone $agora;
        $s->setTime((int)$m[1], (int)$m[2], 0);
        if ($s > $agora) {
            $s->modify('-1 day');
        }
        return $s;
    }
    return null;
}

function agendamento_proximo(string $modo, string $hora, DateTime $agora): ?DateTime
{
    $s = agendamento_slot_atual($modo, $hora, $agora);
    if (!$s) {
        return null;
    }
    $p = clone $s;
    $p->modify($modo === '30min' ? '+30 minutes' : '+1 day');
    return $p;
}

function agendamento_salvar(PDO $pdo, string $modo, string $hora, ?DateTime $agora = null): array
{
    $agora ??= new DateTime('now');
    $hora = trim($hora);
    if (!in_array($modo, AGENDA_MODOS, true)) {
        return [false, 'Escolha uma opção de agendamento.'];
    }
    if ($modo === 'diario' && !preg_match(HORA_RE, $hora)) {
        return [false, 'Informe o horário no formato HH:MM (ex.: 02:00).'];
    }
    if (!preg_match(HORA_RE, $hora)) {
        $hora = '02:00';
    }
    agendamento_obter($pdo);
    $slot = agendamento_slot_atual($modo, $hora, $agora);
    $pdo->prepare('UPDATE agendamento SET modo = ?, hora = ?, ultimo_slot = ? WHERE id = 1')
        ->execute([$modo, $hora, $slot ? $slot->format(AGENDA_FMT) : null]);
    return [true, match ($modo) {
        'desligado' => 'Agendamento desligado.',
        '30min'     => 'Agendado: coleta a cada 30 minutos (em :00 e :30).',
        default     => "Agendado: coleta uma vez ao dia, às $hora.",
    }];
}

function agendamento_reservar(PDO $pdo, string $slot): bool
{
    $st = $pdo->prepare('UPDATE agendamento SET ultimo_slot = ? WHERE id = 1 AND (ultimo_slot IS NULL OR ultimo_slot < ?)');
    $st->execute([$slot, $slot]);
    return $st->rowCount() === 1;
}

function agendamento_devolver(PDO $pdo, string $slot, ?string $anterior): void
{
    $pdo->prepare('UPDATE agendamento SET ultimo_slot = ? WHERE id = 1 AND ultimo_slot = ?')->execute([$anterior, $slot]);
}

function agendamento_pulso(PDO $pdo, DateTime $agora): void
{
    $pdo->prepare('UPDATE agendamento SET ultimo_pulso = ? WHERE id = 1')->execute([$agora->format(AGENDA_FMT)]);
}

function agendamento_resultado(PDO $pdo, DateTime $quando, string $estado, string $mensagem): void
{
    $msg = function_exists('mb_substr') ? mb_substr($mensagem, 0, 500, 'UTF-8') : substr($mensagem, 0, 500);
    $pdo->prepare('UPDATE agendamento SET ultima_execucao = ?, ultimo_estado = ?, ultima_mensagem = ? WHERE id = 1')
        ->execute([$quando->format(AGENDA_FMT), $estado, $msg]);
}
