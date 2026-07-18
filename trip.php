<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

$pdo    = db();
$tripId = (int)($_GET['id'] ?? $_POST['trip_id'] ?? 0);

// só o navegador que criou a trip é reconhecido como líder/admin dela
$isAdminSession = !empty($_SESSION['trip_admin'][$tripId] ?? false);

$stmt = $pdo->prepare('SELECT * FROM trips WHERE id = ?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();
if (!$trip) {
    redirect('index.php');
}

$isClosed = !empty($trip['closed_at']);
$error    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($isClosed && in_array($action, ['add_kill', 'delete_kill', 'add_member', 'remove_member'], true)) {
        $error = 'A trip está fechada. Só o líder reabrindo pra mexer nos kills.';
    } elseif ($action === 'add_kill') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $value    = parse_gp($_POST['value'] ?? '');
        $note     = trim($_POST['note'] ?? '');

        $check = $pdo->prepare('SELECT COUNT(*) FROM members WHERE id = ? AND trip_id = ?');
        $check->execute([$memberId, $tripId]);

        if ($value === null) {
            $error = 'Valor inválido. Usa algo tipo 500k, 1.2m ou 750000.';
        } elseif (!$check->fetchColumn()) {
            $error = 'Selecione quem pegou a chave.';
        } else {
            $pdo->prepare('INSERT INTO kills (trip_id, member_id, value, note) VALUES (?, ?, ?, ?)')
                ->execute([$tripId, $memberId, $value, $note]);
            redirect('trip.php?id=' . $tripId);
        }
    } elseif ($action === 'delete_kill') {
        $pdo->prepare('DELETE FROM kills WHERE id = ? AND trip_id = ?')
            ->execute([(int)($_POST['kill_id'] ?? 0), $tripId]);
        redirect('trip.php?id=' . $tripId);
    } elseif ($action === 'add_member') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM members WHERE trip_id = ? AND LOWER(name) = LOWER(?)');
            $dup->execute([$tripId, $name]);
            if ($dup->fetchColumn()) {
                $error = 'Já tem alguém com esse nome na trip.';
            } else {
                // quem entra depois só divide os kills a partir de agora
                $pdo->prepare('INSERT INTO members (trip_id, name, joined_at) VALUES (?, ?, ?)')
                    ->execute([$tripId, $name, date('Y-m-d H:i:s')]);
                redirect('trip.php?id=' . $tripId);
            }
        }
    } elseif ($action === 'remove_member') {
        if (!$isAdminSession) {
            $error = 'Só o líder que criou a trip pode remover membros.';
        } else {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $check    = $pdo->prepare('SELECT COUNT(*) FROM members WHERE id = ? AND trip_id = ?');
            $check->execute([$memberId, $tripId]);
            if ($check->fetchColumn()) {
                if ((int)($trip['leader_id'] ?? 0) === $memberId) {
                    $pdo->prepare('UPDATE trips SET leader_id = NULL WHERE id = ?')->execute([$tripId]);
                }
                // apaga também os kills dele(a) por causa do ON DELETE CASCADE em members
                $pdo->prepare('DELETE FROM members WHERE id = ? AND trip_id = ?')->execute([$memberId, $tripId]);
            }
            redirect('trip.php?id=' . $tripId);
        }
    } elseif ($action === 'set_leader') {
        if (!$isAdminSession) {
            $error = 'Só o líder que criou a trip pode trocar o líder.';
        } else {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $check    = $pdo->prepare('SELECT COUNT(*) FROM members WHERE id = ? AND trip_id = ?');
            $check->execute([$memberId, $tripId]);
            if ($check->fetchColumn()) {
                $pdo->prepare('UPDATE trips SET leader_id = ? WHERE id = ?')->execute([$memberId, $tripId]);
            }
            redirect('trip.php?id=' . $tripId);
        }
    } elseif ($action === 'close_trip') {
        $pdo->prepare('UPDATE trips SET closed_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $tripId]);
        redirect('trip.php?id=' . $tripId);
    } elseif ($action === 'reopen_trip') {
        $pdo->prepare('UPDATE trips SET closed_at = NULL WHERE id = ?')->execute([$tripId]);
        redirect('trip.php?id=' . $tripId);
    }
}

$stmt = $pdo->prepare('SELECT * FROM members WHERE trip_id = ? ORDER BY LOWER(name)');
$stmt->execute([$tripId]);
$members = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT k.*, m.name AS member_name
     FROM kills k JOIN members m ON m.id = k.member_id
     WHERE k.trip_id = ?
     ORDER BY k.id DESC'
);
$stmt->execute([$tripId]);
$kills = $stmt->fetchAll();

$leaderName = null;
foreach ($members as $m) {
    if ((int)$m['id'] === (int)($trip['leader_id'] ?? 0)) {
        $leaderName = $m['name'];
    }
}

// ---- resumo por membro + split líquido centralizado no líder ----
// desconta 10% do G.E.; cada kill é dividido só entre quem já estava na trip
$geTaxRate = 0.10;
$grossTotal = 0;
$netTotal   = 0;
$byMember = [];
foreach ($members as $m) {
    $byMember[$m['id']] = [
        'name'      => $m['name'],
        'joined_at' => $m['joined_at'],
        'keys'      => 0,
        'collected' => 0,
        'fair'      => 0.0,
    ];
}

$killSplitN = [];
$killNet    = [];
foreach ($kills as $k) {
    $gross = (int)$k['value'];
    $net   = (int)round($gross * (1 - $geTaxRate));
    $grossTotal += $gross;
    $netTotal += $net;
    $killNet[$k['id']] = $net;
    $byMember[$k['member_id']]['keys']++;
    $byMember[$k['member_id']]['collected'] += $net;

    $present = [];
    foreach ($members as $m) {
        if (empty($m['joined_at']) || $m['joined_at'] <= $k['created_at']) {
            $present[] = $m['id'];
        }
    }
    if (!$present) {
        $present = array_column($members, 'id');
    }
    $killSplitN[$k['id']] = count($present);

    $per = $net / count($present);
    foreach ($present as $mid) {
        $byMember[$mid]['fair'] += $per;
    }
}

$n = count($members);

// cota uniforme (todo mundo desde o início) ou variável (alguém entrou depois)?
$fairValues   = array_column($byMember, 'fair');
$fullShare    = $fairValues ? max($fairValues) : 0;
$uniformShare = $n > 0 && ($fullShare - min($fairValues ?: [0]) < 1);

// cotas menores de quem entrou no meio da trip (pra mostrar no card)
$lateShares = [];
if (!$uniformShare) {
    foreach ($fairValues as $v) {
        if ($fullShare - $v >= 1) {
            $lateShares[(string)(int)round($v)] = (int)round($v);
        }
    }
    rsort($lateShares);
}

// Fluxo combinado, mas líquido: cada um só manda/recebe a diferença entre o
// que já coletou nas próprias keys e a cota dele — sem pagar bruto pro líder
// e depois receber de volta (fica confuso com muitos kills desiguais).
$transfers = [];
if ($leaderName) {
    foreach ($byMember as $mid => $info) {
        if ((int)$mid === (int)($trip['leader_id'] ?? 0)) {
            continue;
        }
        $net = $info['collected'] - $info['fair'];
        if ($net >= 1) {
            $transfers[] = [
                'from'   => $info['name'],
                'to'     => $leaderName,
                'amount' => (int)round($net),
                'stage'  => 'pay',
            ];
        } elseif ($net <= -1) {
            $transfers[] = [
                'from'   => $leaderName,
                'to'     => $info['name'],
                'amount' => (int)round(-$net),
                'stage'  => 'receive',
            ];
        }
    }
}

// Placar da trip: ranking bruto de quem coletou mais/menos valor que a
// média do grupo, tipo bolsa de valores — não tem relação com a cota justa
// (Situação); é só quem se saiu melhor/pior coletando keys.
$avgCollected = $n > 0 ? $netTotal / $n : 0;
$scoreboard = [];
foreach ($byMember as $mid => $info) {
    $scoreboard[] = [
        'name'      => $info['name'],
        'is_leader' => (int)$mid === (int)($trip['leader_id'] ?? 0),
        'keys'      => $info['keys'],
        'collected' => $info['collected'],
        'pct'       => $avgCollected >= 1 ? (($info['collected'] - $avgCollected) / $avgCollected * 100) : null,
    ];
}
usort($scoreboard, fn($a, $b) => $b['collected'] <=> $a['collected']);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($trip['name']) ?> — Rune Split</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='0.9em' font-size='90'%3E⚔️%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<aside class="rules-box" aria-label="Regras da divisão">
    <div class="rules-box-title">📜 Como dividimos</div>
    <ol>
        <li>Registramos o valor <strong>bruto</strong> de cada key.</li>
        <li>Cada pessoa vende no G.E. os itens das <strong>próprias keys</strong>.</li>
        <li>Descontamos <strong>10% da taxa do G.E.</strong> do valor vendido.</li>
        <li>Comparamos o que cada um já vendeu com a cota dele.</li>
        <li>Só a <strong>diferença</strong> circula: quem vendeu mais que a cota manda o excedente ao líder; quem vendeu menos recebe a diferença dele.</li>
        <li>Quem entrar depois só participa dos próximos kills.</li>
    </ol>
    <div class="rules-example">
        <span>Exemplo</span>
        <strong>500K → 450K líquidos</strong>
        <small>450K ÷ número de participantes</small>
    </div>
</aside>
<div class="wrap">
    <div class="credits-banner" title="o lendário criador do app 👑">✨ AGRADEÇAM AO MATHEUS WAIF ✨</div>
    <header class="topbar">
        <p><a href="index.php">← todas as trips</a></p>
        <h1>⚔️ <?= e($trip['name']) ?></h1>
        <p class="sub">
            👑 líder: <strong><?= $leaderName ? e($leaderName) : '—' ?></strong>
            · <?= $n ?> membros · <?= count($kills) ?> kills
            · criada em <?= e($trip['created_at']) ?>
        </p>
    </header>

    <?php if ($error): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($isClosed): ?>
        <div class="banner-closed">
            🔒 Trip <strong>fechada</strong> em <?= e($trip['closed_at']) ?> — o acerto abaixo é o final.
        </div>
    <?php endif; ?>

    <section class="toolbar">
        <?php if ($isAdminSession): ?>
        <form method="post" class="leader-form">
            <input type="hidden" name="action" value="set_leader">
            <input type="hidden" name="trip_id" value="<?= $tripId ?>">
            <label class="inline-label">
                👑 Líder
                <select name="member_id" onchange="this.form.submit()">
                    <?php if (!$leaderName): ?>
                        <option value="">— definir —</option>
                    <?php endif; ?>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)($trip['leader_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($m['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php else: ?>
            <span class="inline-label muted" title="Só o navegador de quem criou a trip pode trocar o líder">
                👑 Líder: <strong><?= $leaderName ? e($leaderName) : '—' ?></strong>
            </span>
        <?php endif; ?>

        <?php if ($isClosed): ?>
            <form method="post" onsubmit="return confirm('Reabrir a trip? Vai dar pra registrar kills de novo.')">
                <input type="hidden" name="action" value="reopen_trip">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <button type="submit" class="btn">🔓 Reabrir trip</button>
            </form>
        <?php else: ?>
            <form method="post" onsubmit="return confirm('Fechar a trip? Ninguém mais registra kill e o acerto vira o final. (Dá pra reabrir se precisar.)')">
                <input type="hidden" name="action" value="close_trip">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <button type="submit" class="btn">🔒 Fechar trip</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="stats">
        <div class="stat">
            <span class="stat-label">Loot líquido</span>
            <span class="stat-value gp" title="<?= e(format_gp_full($netTotal)) ?>"><?= format_gp($netTotal) ?></span>
            <span class="stat-sub">
                bruto <?= format_gp($grossTotal) ?> · taxa G.E. <?= format_gp($grossTotal - $netTotal) ?> (10%)
            </span>
        </div>
        <div class="stat">
            <span class="stat-label">Cota por pessoa</span>
            <span class="stat-value gp" title="<?= e(format_gp_full($fullShare)) ?>"><?= format_gp($fullShare) ?></span>
            <?php if (!$uniformShare): ?>
                <span class="stat-sub" title="Quem entrou no meio da trip só divide os kills a partir daí">
                    quem chegou depois: <?= implode(' · ', array_map('format_gp', $lateShares)) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="stat">
            <span class="stat-label">Chaves pegas</span>
            <span class="stat-value"><?= count($kills) ?></span>
        </div>
    </section>

    <?php if (!$isClosed): ?>
    <section class="card">
        <h2>💀 Novo kill</h2>
        <form method="post" class="kill-form" novalidate>
            <input type="hidden" name="action" value="add_kill">
            <input type="hidden" name="trip_id" value="<?= $tripId ?>">
            <label>
                Valor do loot
                <input type="text" name="value" id="value-input" placeholder="500k / 1.2m / 750000"
                       autocomplete="off" required autofocus>
                <span class="hint" id="value-hint">&nbsp;</span>
            </label>
            <label>
                Quem pegou a chave
                <select name="member_id" required>
                    <option value="">— escolher —</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Obs (opcional)
                <input type="text" name="note" placeholder="nick da vítima, item raro...">
            </label>
            <button type="submit" class="btn primary">Registrar kill</button>
        </form>
    </section>
    <?php endif; ?>

    <div class="two-col">
        <section class="card">
            <h2>📊 Resumo por membro</h2>
            <table>
                <thead>
                <tr>
                    <th>Membro</th>
                    <th>Situação</th>
                    <?php if ($isAdminSession && !$isClosed): ?><th></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($byMember as $mid => $info):
                    $isLeaderRow = (int)$mid === (int)($trip['leader_id'] ?? 0);
                    if (!$leaderName) {
                        $pillClass = 'pill-even';
                        $pillText  = '—';
                    } elseif ($isLeaderRow) {
                        $pillClass = 'pill-leader';
                        $pillText  = '👑 segura o saldo';
                    } else {
                        $memberNet = $info['collected'] - $info['fair'];
                        if ($memberNet >= 1) {
                            $pillClass = 'pill-pay';
                            $pillText  = '➡ envia ' . format_gp($memberNet);
                        } elseif ($memberNet <= -1) {
                            $pillClass = 'pill-receive';
                            $pillText  = '⬅ recebe ' . format_gp(-$memberNet);
                        } else {
                            $pillClass = 'pill-even';
                            $pillText  = 'quite ✅';
                        }
                    }
                ?>
                    <tr>
                        <td>
                            <span class="name"><?= $isLeaderRow ? '👑 ' : '' ?><?= e($info['name']) ?></span>
                            <?php if (!empty($info['joined_at'])): ?>
                                <span class="muted joined-late" title="Entrou depois: só divide os kills a partir daí">entrou <?= e(substr($info['joined_at'], 11, 5)) ?></span>
                            <?php endif; ?>
                            <span class="sub-detail"><?= $info['keys'] ?> chave<?= $info['keys'] === 1 ? '' : 's' ?> · vendeu <?= format_gp($info['collected']) ?></span>
                        </td>
                        <td>
                            <span class="pill <?= $pillClass ?>"><?= e($pillText) ?></span>
                            <span class="sub-detail" title="<?= e(format_gp_full($info['fair'])) ?>">cota: <?= format_gp($info['fair']) ?></span>
                        </td>
                        <?php if ($isAdminSession && !$isClosed): ?>
                        <td>
                            <form method="post" class="remove-member-form"
                                  data-name="<?= e($info['name']) ?>"
                                  data-keys="<?= (int)$info['keys'] ?>"
                                  data-leader="<?= (int)$mid === (int)($trip['leader_id'] ?? 0) ? '1' : '0' ?>">
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                                <input type="hidden" name="member_id" value="<?= (int)$mid ?>">
                                <button type="submit" class="btn danger small" title="Remover da trip">x</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$isClosed): ?>
            <form method="post" class="inline-form" novalidate>
                <input type="hidden" name="action" value="add_member">
                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                <input type="text" name="name" placeholder="Chegou alguém? Adiciona aqui..." required>
                <button type="submit" class="btn small">+ add</button>
            </form>
            <p class="hint muted">Quem entra agora só divide os kills daqui pra frente.</p>
            <?php endif; ?>
            <?php if (!$isAdminSession): ?>
            <p class="hint muted">Só o navegador de quem criou a trip pode trocar o líder ou remover membros.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>💰 <?= $isClosed ? 'Acerto final' : 'Acerto do split' ?></h2>
            <?php if (!$kills): ?>
                <p class="muted">Registra os kills que eu desconto 10% do G.E. e calculo o split.</p>
            <?php elseif (!$leaderName): ?>
                <p class="muted">Defina o líder para calcular a concentração e a distribuição do dinheiro.</p>
            <?php elseif (!$transfers): ?>
                <p class="muted">Todo mundo quites — ninguém deve nada. 🎉</p>
            <?php else: ?>
                <ul class="transfers">
                    <?php foreach ($transfers as $t): ?>
                        <li>
                            <span class="muted"><?= $t['stage'] === 'pay' ? 'Coletou acima da cota:' : 'Coletou abaixo da cota:' ?></span>
                            <strong><?= e($t['from']) ?></strong> paga
                            <span class="gp" title="<?= e(format_gp_full($t['amount'])) ?>"><?= format_gp($t['amount']) ?></span>
                            para <strong><?= e($t['to']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="hint">Cada um vende suas keys · só a diferença pra cota circula: quem coletou mais que a cota manda pro líder, quem coletou menos recebe dele.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="card">
        <h2>📈 Placar da trip</h2>
        <?php if (!$kills): ?>
            <p class="muted">Registra os kills pra ver o placar de quem coletou mais.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Membro</th>
                    <th>Chaves</th>
                    <th>Coletou</th>
                    <th>Vs. média do grupo</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($scoreboard as $i => $s): ?>
                    <tr>
                        <td class="muted">#<?= $i + 1 ?></td>
                        <td><?= $s['is_leader'] ? '👑 ' : '' ?><?= e($s['name']) ?></td>
                        <td><?= $s['keys'] ?></td>
                        <td class="gp" title="<?= e(format_gp_full($s['collected'])) ?>"><?= format_gp($s['collected']) ?></td>
                        <td>
                            <?php if ($s['pct'] === null || abs($s['pct']) < 0.05): ?>
                                <span class="muted">— na média</span>
                            <?php elseif ($s['pct'] > 0): ?>
                                <span class="pos">▲ +<?= number_format($s['pct'], 1, ',', '.') ?>%</span>
                            <?php else: ?>
                                <span class="neg">▼ <?= number_format($s['pct'], 1, ',', '.') ?>%</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint muted">Média do grupo nessa trip: <strong class="gp"><?= format_gp($avgCollected) ?></strong> por pessoa. É só um placar de quem coletou mais ou menos — não muda o split justo (isso está na "Situação" lá em cima).</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>🗡️ Kills (<?= count($kills) ?>)</h2>
        <?php if (!$kills): ?>
            <p class="muted">Nenhum kill registrado ainda.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Bruto</th>
                    <th>Líquido</th>
                    <th>Chave com</th>
                    <th>Split</th>
                    <th>Obs</th>
                    <th>Hora</th>
                    <?php if (!$isClosed): ?><th></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php $i = count($kills);
                foreach ($kills as $k): ?>
                    <tr>
                        <td class="muted"><?= $i-- ?></td>
                        <td class="gp" title="<?= e(format_gp_full((int)$k['value'])) ?>"><?= format_gp((int)$k['value']) ?></td>
                        <td class="gp" title="Após taxa de 10% do G.E."><?= format_gp($killNet[$k['id']]) ?></td>
                        <td><?= e($k['member_name']) ?></td>
                        <td class="muted" title="Dividido entre quem estava na trip nesse momento">÷<?= $killSplitN[$k['id']] ?></td>
                        <td class="muted"><?= e($k['note']) ?></td>
                        <td class="muted"><?= e(substr($k['created_at'], 11, 5)) ?></td>
                        <?php if (!$isClosed): ?>
                        <td>
                            <form method="post" onsubmit="return confirm('Apagar esse kill de <?= format_gp((int)$k['value']) ?>?')">
                                <input type="hidden" name="action" value="delete_kill">
                                <input type="hidden" name="trip_id" value="<?= $tripId ?>">
                                <input type="hidden" name="kill_id" value="<?= (int)$k['id'] ?>">
                                <button type="submit" class="btn danger small">x</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<?php if (!$isClosed): ?>
<script>
// preview ao vivo do valor digitado (500k -> 500.000 gp)
const input = document.getElementById('value-input');
const hint  = document.getElementById('value-hint');

function parseGp(s) {
    s = s.toLowerCase().trim();
    const m = s.match(/^([\d.,\s]+)\s*([kmb]?)$/);
    if (!m) return null;
    let num = m[1].replace(/\s/g, '');
    const suffix = m[2];
    let value;
    if (suffix) {
        num = num.replace(',', '.');
        if ((num.match(/\./g) || []).length > 1 || isNaN(Number(num))) return null;
        value = Number(num) * {k: 1e3, m: 1e6, b: 1e9}[suffix];
    } else {
        const digits = num.replace(/\D/g, '');
        if (!digits) return null;
        value = Number(digits);
    }
    return value >= 1 ? Math.round(value) : null;
}

input.addEventListener('input', () => {
    input.classList.remove('invalid');
    hint.classList.remove('err');
    const v = parseGp(input.value);
    hint.textContent = v === null
        ? (input.value.trim() ? '❌ valor inválido' : ' ')
        : '= ' + v.toLocaleString('pt-BR') + ' gp';
});

// validação própria, sem o balão nativo do navegador
const killForm  = document.querySelector('.kill-form');
const memberSel = killForm.querySelector('select[name="member_id"]');

killForm.addEventListener('submit', (ev) => {
    let bad = null;
    if (parseGp(input.value) === null) {
        hint.textContent = '❌ informe o valor do loot (ex: 500k, 1.2m)';
        hint.classList.add('err');
        input.classList.add('invalid');
        bad = input;
    }
    if (!memberSel.value) {
        memberSel.classList.add('invalid');
        if (!bad) bad = memberSel;
    }
    if (bad) {
        ev.preventDefault();
        bad.focus();
    }
});
memberSel.addEventListener('change', () => memberSel.classList.remove('invalid'));

document.querySelectorAll('.remove-member-form').forEach((form) => {
    form.addEventListener('submit', (ev) => {
        const name     = form.dataset.name;
        const keys     = parseInt(form.dataset.keys, 10) || 0;
        const isLeader = form.dataset.leader === '1';
        let msg = `Remover ${name}${isLeader ? ' (líder)' : ''} da trip?`;
        if (keys > 0) {
            msg += ` Isso apaga ${keys} chave${keys > 1 ? 's' : ''} que ${name} registrou.`;
        }
        if (!confirm(msg)) {
            ev.preventDefault();
        }
    });
});

const addMemberForm = document.querySelector('.inline-form');
if (addMemberForm) {
    const nameInput = addMemberForm.querySelector('input[name="name"]');
    addMemberForm.addEventListener('submit', (ev) => {
        if (!nameInput.value.trim()) {
            ev.preventDefault();
            nameInput.classList.add('invalid');
            nameInput.focus();
        }
    });
    nameInput.addEventListener('input', () => nameInput.classList.remove('invalid'));
}
</script>
<?php endif; ?>
<script src="assets/fx.js" defer></script>
</body>
</html>
