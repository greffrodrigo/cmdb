<?php
declare(strict_types=1);
require __DIR__ . '/../src/layout.php';
require __DIR__ . '/../src/ambientes.php';
require __DIR__ . '/../src/agendamento.php';

$user = exigir_admin();
$pdo  = db();
sessao();
$chaveOk = chave_cifra_ok();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_csrf('ambientes.php');
    if (($_POST['acao'] ?? '') === 'agendamento') {
        [$ok, $msg] = agendamento_salvar($pdo, (string)($_POST['modo'] ?? ''), (string)($_POST['hora'] ?? ''));
        flash($ok ? 'ok' : 'erro', $msg);
        redirecionar('ambientes.php');
    }
    if (!$chaveOk) {
        flash('erro', "Defina 'chave_cifra' no config.php antes de cadastrar ambientes.");
        redirecionar('ambientes.php');
    }
    [$ok, $res] = ambiente_criar($pdo, $_POST, chave_cifra());
    if ($ok) {
        flash('ok', 'Ambiente cadastrado. Clique em "Coletar agora" na linha dele para fazer a primeira coleta.');
    } else {
        flash('erro', (string)$res);
        $_SESSION['old'] = array_diff_key($_POST, ['token' => 1, 'csrf' => 1]);
    }
    redirecionar('ambientes.php');
}

$old = $_SESSION['old'] ?? [];
unset($_SESSION['old']);
$lista = ambientes_listar($pdo);
$codigosBanco = array_column($lista, 'codigo');
$deArquivo = array_values(array_filter(ambientes_de_arquivo(), fn($a) => !in_array($a['codigo'], $codigosBanco, true)));
try {
    $agenda = agendamento_obter($pdo);
} catch (Throwable) {
    $agenda = null;
}

cabecalho('Ambientes', $user, 'ambientes');
?>
<h1 class="page-title">Ambientes</h1>

<?php if (!$chaveOk): ?>
  <div class="alert">Chave de cifra não configurada (defina chave_cifra no config.php).</div>
<?php endif; ?>

<div class="toolbar">
  <button class="btn btn-primary btn-atualizar" type="button">&#8635; Coletar todos</button>
  <span class="muted">Os ambientes ativos são coletados em sequência, na ordem indicada.</span>
</div>
<div id="msgColeta" class="alert info" hidden></div>

<article class="card">
  <div class="table-wrap">
  <table>
    <thead><tr><th>ORDEM</th><th>AMBIENTE</th><th>ENDEREÇO</th><th>USUÁRIO DA API</th><th>SITUAÇÃO</th><th>ÚLTIMA COLETA</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lista && !$deArquivo): ?>
      <tr><td colspan="7" class="muted center">Nenhum ambiente cadastrado. Use o formulário abaixo.</td></tr>
    <?php endif; ?>
    <?php foreach ($lista as $a): ?>
      <tr class="<?= $a['ativo'] ? '' : 'inativo' ?>">
        <td class="muted"><?= (int)$a['ordem'] ?></td>
        <td><strong><?= h($a['nome']) ?></strong><br><span class="iconf"><?= h($a['codigo']) ?></span></td>
        <td><?= h($a['host']) ?>:<?= (int)$a['porta'] ?></td>
        <td class="muted"><?= h($a['usuario_api']) ?></td>
        <td><span class="badge <?= $a['ativo'] ? 'b-ok' : 'b-off' ?>"><?= $a['ativo'] ? 'ATIVO' : 'INATIVO' ?></span></td>
        <td>
          <?php if ($a['ultimo_estado']): ?>
            <span class="badge <?= $a['ultimo_estado'] === 'ok' ? 'b-ok' : 'b-err' ?>"><?= h(strtoupper($a['ultimo_estado'] === 'inacessivel' ? 'inacessível' : $a['ultimo_estado'])) ?></span>
            <small class="muted"><?= h(data_br($a['ultima_coleta_em'])) ?></small><br>
            <small class="msg-coleta"><?= h($a['ultima_mensagem']) ?></small>
          <?php else: ?>
            <span class="muted">nunca coletado</span>
          <?php endif; ?>
        </td>
        <td class="acoes">
          <?php if ($a['ativo']): ?>
            <button class="btn btn-sm btn-atualizar" type="button" data-amb="<?= h($a['codigo']) ?>">&#8635; Coletar agora</button>
          <?php endif; ?>
          <a class="btn btn-sm" href="ambiente-editar.php?id=<?= (int)$a['id'] ?>">Editar</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($deArquivo as $a): ?>
      <tr>
        <td class="muted">-</td>
        <td><strong><?= h($a['nome']) ?></strong><br><span class="iconf"><?= h($a['codigo']) ?></span></td>
        <td><?= h($a['host']) ?></td>
        <td class="muted">definido em arquivo</td>
        <td><span class="badge b-gray">ARQUIVO</span></td>
        <td class="muted">Somente leitura: edite em /etc/cmdb/ambientes/<?= h($a['codigo']) ?>.env</td>
        <td class="acoes"><button class="btn btn-sm btn-atualizar" type="button" data-amb="<?= h($a['codigo']) ?>">&#8635; Coletar agora</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</article>

<article class="card pad agenda">
  <?php if ($agenda === null): ?>
    <div class="alert">Tabela de agendamento ausente.</div>
  <?php else:
    $agora = new DateTime('now');
    $prox = agendamento_proximo((string)$agenda['modo'], (string)$agenda['hora'], $agora);
    $pulso = $agenda['ultimo_pulso'] ? new DateTime((string)$agenda['ultimo_pulso']) : null;
    $vivo = $pulso && ($agora->getTimestamp() - $pulso->getTimestamp()) <= 180;
  ?>
    <form method="post" class="agenda-linha" autocomplete="off">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="agendamento">
      <strong>Agendamento</strong>
      <select name="modo" id="agModo" aria-label="Frequência da coleta automática">
        <option value="desligado" <?= $agenda['modo'] === 'desligado' ? 'selected' : '' ?>>Desligado</option>
        <option value="30min" <?= $agenda['modo'] === '30min' ? 'selected' : '' ?>>A cada 30 minutos</option>
        <option value="diario" <?= $agenda['modo'] === 'diario' ? 'selected' : '' ?>>Uma vez ao dia</option>
      </select>
      <span id="agHora" class="agenda-hora" <?= $agenda['modo'] === 'diario' ? '' : 'hidden' ?>>às
        <input type="time" name="hora" value="<?= h((string)$agenda['hora']) ?>" aria-label="Horário da coleta diária">
      </span>
      <button class="btn btn-sm btn-primary" type="submit">Salvar</button>
      <span class="muted agenda-info" title="<?= h((string)$agenda['ultima_mensagem']) ?>">
        Próxima: <strong><?= $prox ? h($prox->format('d/m H:i')) : '-' ?></strong>
        &middot; Última: <?= $agenda['ultima_execucao'] ? h((new DateTime((string)$agenda['ultima_execucao']))->format('d/m H:i')) . ' (' . h((string)$agenda['ultimo_estado']) . ')' : '-' ?>
      </span>
    </form>
    <?php if ($agenda['modo'] !== 'desligado' && !$vivo): ?>
      <div class="alert agenda-aviso">O agendador não está rodando (última atividade: <?= h($pulso ? $pulso->format('d/m/Y H:i:s') : 'nunca') ?>).</div>
    <?php endif; ?>
    <script>
      (function () {
        var m = document.getElementById('agModo'), h = document.getElementById('agHora');
        m.addEventListener('change', function () { h.hidden = (m.value !== 'diario'); });
      })();
    </script>
  <?php endif; ?>
</article>

<article class="card pad">
  <h2 class="sec-title">Novo ambiente</h2>
  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_campo() ?>
    <label>Nome do ambiente
      <input name="nome" required maxlength="120" value="<?= h($old['nome'] ?? '') ?>" <?= $chaveOk ? '' : 'disabled' ?>>
    </label>
    <label>Endereço (IP ou nome DNS)
      <input name="host" required value="<?= h($old['host'] ?? '') ?>" <?= $chaveOk ? '' : 'disabled' ?>>
    </label>
    <label>Porta da API
      <input name="porta" type="number" min="1" max="65535" value="<?= h((string)($old['porta'] ?? '8006')) ?>" <?= $chaveOk ? '' : 'disabled' ?>>
    </label>
    <label>Usuário da API (usuário!token)
      <input name="usuario_api" required value="<?= h($old['usuario_api'] ?? '') ?>" <?= $chaveOk ? '' : 'disabled' ?>>
    </label>
    <label>Segredo do token
      <input name="token" type="password" required autocomplete="new-password" <?= $chaveOk ? '' : 'disabled' ?>>
    </label>
    <label>Ordem da coleta
      <input name="ordem" type="number" value="<?= h((string)($old['ordem'] ?? '100')) ?>" <?= $chaveOk ? '' : 'disabled' ?>>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit" <?= $chaveOk ? '' : 'disabled' ?>>Cadastrar ambiente</button></div>
  </form>
</article>

<script>
(function () {
  var btns = Array.prototype.slice.call(document.querySelectorAll('.btn-atualizar'));
  var msg = document.getElementById('msgColeta');
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var timer = null, inicio = 0, esperas = 0;

  function trava(sim) { btns.forEach(function (b) { b.disabled = sim; }); }
  function mostra(t, erro) { msg.hidden = false; msg.className = 'alert ' + (erro ? '' : 'info'); msg.textContent = t; }

  function checa() {
    fetch('status.php', {cache: 'no-store'}).then(function (r) { return r.json(); }).then(function (s) {
      if (s.estado === 'executando') { trava(true); mostra(s.mensagem || 'Coleta em andamento'); return; }
      if (inicio && s.atualizado_em && Date.parse(s.atualizado_em) < inicio - 2000 && esperas++ < 10) { return; }
      clearInterval(timer); timer = null; inicio = 0;
      mostra('Coleta finalizada. Atualizando a tela...');
      setTimeout(function () { location.reload(); }, 700);
    }).catch(function () {});
  }

  function iniciar(amb) {
    trava(true);
    var corpo = new URLSearchParams();
    if (amb) { corpo.set('ambiente', amb); }
    fetch('atualizar.php', {method: 'POST', headers: {'X-CSRF-Token': csrf}, body: corpo})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { trava(false); mostra(j.mensagem, true); return; }
        mostra(j.mensagem);
        inicio = j.iniciado_em ? Date.parse(j.iniciado_em) : 0; esperas = 0;
        if (!timer) { timer = setInterval(checa, 3000); }
        setTimeout(checa, 1000);
      })
      .catch(function () { trava(false); mostra('Falha ao iniciar a coleta', true); });
  }

  btns.forEach(function (b) { b.addEventListener('click', function () { iniciar(b.getAttribute('data-amb') || ''); }); });
})();
</script>
<?php rodape();
