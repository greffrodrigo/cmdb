<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const AMB_COLUNAS = 'id, codigo, nome, host, porta, usuario_api, ordem, ativo, ultimo_estado, ultima_mensagem, ultima_coleta_em, criado_em, atualizado_em';

function comprimento(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function chave_cifra(?string $b64 = null): string
{
    $b64 ??= (string)(cfg()['chave_cifra'] ?? '');
    $k = $b64 === '' ? false : base64_decode($b64, true);
    if ($k === false || strlen($k) !== 32) {
        throw new RuntimeException("'chave_cifra' ausente ou inválida no config.php (deve ser base64 de 32 bytes).");
    }
    return $k;
}

function chave_cifra_ok(): bool
{
    try {
        chave_cifra();
        return true;
    } catch (Throwable) {
        return false;
    }
}

function cifrar_token(string $token, string $chave): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($token, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('Falha ao cifrar o token.');
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function decifrar_token(string $cifrado, string $chave): string
{
    $raw = str_starts_with($cifrado, 'v1:') ? base64_decode(substr($cifrado, 3), true) : false;
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Token cifrado em formato inválido.');
    }
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($pt === false) {
        throw new RuntimeException('Não foi possível decifrar o token (a chave_cifra mudou desde o cadastro?).');
    }
    return $pt;
}

function ambiente_normalizar(array $in): array
{
    return [
        'nome'        => trim((string)($in['nome'] ?? '')),
        'host'        => trim((string)($in['host'] ?? '')),
        'porta'       => trim((string)($in['porta'] ?? '')) === '' ? 8006 : (int)trim((string)$in['porta']),
        'usuario_api' => trim((string)($in['usuario_api'] ?? '')),
        'token'       => trim((string)($in['token'] ?? '')),
        'ordem'       => trim((string)($in['ordem'] ?? '')) === '' ? 100 : (int)trim((string)$in['ordem']),
        'ativo'       => !empty($in['ativo']),
    ];
}

function ambiente_validar(array $d, bool $tokenObrigatorio): array
{
    $e = [];
    if ($d['nome'] === '' || comprimento($d['nome']) > 120) {
        $e[] = 'Informe o nome do ambiente (até 120 caracteres).';
    }
    if (!preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,186}[A-Za-z0-9])?|\[[0-9A-Fa-f:]{2,45}\])$/', $d['host'])) {
        $e[] = 'Endereço inválido: informe só o IP ou o nome DNS, sem "https://" e sem a porta.';
    }
    if ($d['porta'] < 1 || $d['porta'] > 65535) {
        $e[] = 'Porta inválida (1 a 65535).';
    }
    if (strlen($d['usuario_api']) > 190 || !preg_match('/^[^@!\s]+@[^@!\s]+![^@!\s]+$/', $d['usuario_api'])) {
        $e[] = 'Usuário da API inválido: use o formato usuario@realm!nome-do-token (ex.: cmdb@pve!coleta).';
    }
    if ($d['token'] === '') {
        if ($tokenObrigatorio) {
            $e[] = 'Informe o segredo do token.';
        }
    } elseif (!preg_match('/^\S{8,200}$/', $d['token'])) {
        $e[] = 'Segredo do token inválido (8 a 200 caracteres, sem espaços).';
    }
    if ($d['ordem'] < -9999 || $d['ordem'] > 99999) {
        $e[] = 'Ordem inválida (-9999 a 99999).';
    }
    return $e;
}

function codigo_de(string $nome): string
{
    $mapa = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n',
    ];
    $s = strtolower(strtr($nome, $mapa));
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = trim(substr($s, 0, 50), '-');
    return $s === '' ? 'ambiente' : $s;
}

function codigo_unico(PDO $pdo, string $base): string
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM ambiente WHERE codigo = ?');
    $codigo = $base;
    for ($i = 2; $i < 1000; $i++) {
        $st->execute([$codigo]);
        if ((int)$st->fetchColumn() === 0) {
            return $codigo;
        }
        $codigo = $base . '-' . $i;
    }
    throw new RuntimeException('Não foi possível gerar um identificador único para o ambiente.');
}

function ambientes_listar(PDO $pdo, bool $apenasAtivos = false): array
{
    $sql = 'SELECT ' . AMB_COLUNAS . ' FROM ambiente' . ($apenasAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, codigo';
    return $pdo->query($sql)->fetchAll();
}

function ambiente_obter(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . AMB_COLUNAS . ' FROM ambiente WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function ambiente_criar(PDO $pdo, array $entrada, string $chave): array
{
    $d = ambiente_normalizar($entrada);
    $erros = ambiente_validar($d, true);
    if ($erros) {
        return [false, implode(' ', $erros)];
    }
    $codigo = codigo_unico($pdo, codigo_de($d['nome']));
    $pdo->prepare(
        'INSERT INTO ambiente (codigo, nome, host, porta, usuario_api, token_cifrado, ordem, ativo) VALUES (?,?,?,?,?,?,?,1)'
    )->execute([$codigo, $d['nome'], $d['host'], $d['porta'], $d['usuario_api'], cifrar_token($d['token'], $chave), $d['ordem']]);
    return [true, ['id' => (int)$pdo->lastInsertId(), 'codigo' => $codigo]];
}

function ambiente_atualizar(PDO $pdo, int $id, array $entrada, string $chave): array
{
    if (!ambiente_obter($pdo, $id)) {
        return [false, 'Ambiente não encontrado.'];
    }
    $d = ambiente_normalizar($entrada);
    $erros = ambiente_validar($d, false);
    if ($erros) {
        return [false, implode(' ', $erros)];
    }
    if ($d['token'] !== '') {
        $pdo->prepare('UPDATE ambiente SET nome=?, host=?, porta=?, usuario_api=?, token_cifrado=?, ordem=?, ativo=? WHERE id=?')
            ->execute([$d['nome'], $d['host'], $d['porta'], $d['usuario_api'], cifrar_token($d['token'], $chave), $d['ordem'], $d['ativo'] ? 1 : 0, $id]);
    } else {
        $pdo->prepare('UPDATE ambiente SET nome=?, host=?, porta=?, usuario_api=?, ordem=?, ativo=? WHERE id=?')
            ->execute([$d['nome'], $d['host'], $d['porta'], $d['usuario_api'], $d['ordem'], $d['ativo'] ? 1 : 0, $id]);
    }
    return [true, 'Ambiente atualizado.'];
}

function ambiente_registrar_resultado(PDO $pdo, string $codigo, string $estado, string $mensagem): void
{
    if (!in_array($estado, ['ok', 'erro', 'inacessivel'], true)) {
        $estado = 'erro';
    }
    $mensagem = function_exists('mb_substr') ? mb_substr($mensagem, 0, 500, 'UTF-8') : substr($mensagem, 0, 500);
    $pdo->prepare('UPDATE ambiente SET ultimo_estado = ?, ultima_mensagem = ?, ultima_coleta_em = ? WHERE codigo = ?')
        ->execute([$estado, $mensagem, date('Y-m-d H:i:s'), $codigo]);
}

function sh_quote(string $v): string
{
    return "'" . str_replace("'", "'\\''", $v) . "'";
}

function ambiente_env_texto(PDO $pdo, string $codigo, string $chave): string
{
    $st = $pdo->prepare('SELECT nome, host, porta, usuario_api, token_cifrado FROM ambiente WHERE codigo = ? AND ativo = 1');
    $st->execute([$codigo]);
    $a = $st->fetch();
    if (!$a) {
        throw new RuntimeException("Ambiente '$codigo' não encontrado ou inativo.");
    }
    return 'AMBIENTE_NOME=' . sh_quote((string)$a['nome']) . "\n"
        . 'PVE_HOST=' . sh_quote((string)$a['host']) . "\n"
        . 'PVE_PORT=' . sh_quote((string)$a['porta']) . "\n"
        . 'PVE_USER=' . sh_quote((string)$a['usuario_api']) . "\n"
        . 'PVE_TOKEN=' . sh_quote(decifrar_token((string)$a['token_cifrado'], $chave)) . "\n";
}

function ambientes_de_arquivo(?string $dir = null): array
{
    $dir ??= getenv('AMBIENTES_DIR') ?: '/etc/cmdb/ambientes';
    $out = [];
    foreach (glob(rtrim($dir, '/') . '/*.env') ?: [] as $f) {
        $codigo = basename($f, '.env');
        if (!preg_match(CODIGO_RE, $codigo) || !is_readable($f)) {
            continue;
        }
        $nome = $host = '';
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $linha) {
            if (preg_match('/^(AMBIENTE_NOME|PVE_HOST)=(.*)$/', $linha, $m)) {
                $v = trim($m[2]);
                $v = preg_replace('/^([\'"])(.*)\1$/', '$2', $v);
                $v = preg_replace('/\\\\(.)/', '$1', (string)$v);
                if ($m[1] === 'AMBIENTE_NOME') {
                    $nome = (string)$v;
                } else {
                    $host = (string)$v;
                }
            }
        }
        $out[] = ['codigo' => $codigo, 'nome' => $nome !== '' ? $nome : $codigo, 'host' => $host];
    }
    return $out;
}
