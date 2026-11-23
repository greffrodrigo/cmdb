<?php
declare(strict_types=1);

function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php não encontrado (copie config.sample.php para config.php).');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'America/Sao_Paulo');
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $off = (new DateTime('now'))->format('P');
            $pdo->exec("SET time_zone = '" . $off . "'");
        }
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const CODIGO_RE = '/^[a-z0-9][a-z0-9_-]*$/';
const CAMPOS_RECURSO = ['vcpus', 'memory_gb', 'disk_gb', 'network'];

function data_br(?string $s, bool $segundos = true): string
{
    return $s ? (new DateTime($s))->format($segundos ? 'd/m/Y H:i:s' : 'd/m/Y H:i') : '-';
}
