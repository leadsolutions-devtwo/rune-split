<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$pdo    = db();
$tripId = (int)($_GET['id'] ?? $_POST['trip_id'] ?? 0);

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

    if ($isClosed && in_array($action, ['add_kill', 'delete_kill', 'add_member'], true)) {
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
    } elseif ($action === 'set_leader') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $check    = $pdo->prepare('SELECT COUNT(*) FROM members WHERE id = ? AND trip_id = ?');
        $check->execute([$memberId, $tripId]);
        if ($check->fetchColumn()) {
            $pdo->prepare('UPDATE trips SET leader_id = ? WHERE id = ?')->execute([$memberId, $tripId]);
        }
        redirect('trip.php?id=' . $tripId);
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

// Fluxo combinado: quem pegou a key manda o líquido ao líder; o líder distribui.
$transfers = [];
if ($leaderName) {
    foreach ($byMember as $mid => $info) {
        if ((int)$mid !== (int)($trip['leader_id'] ?? 0) && $info['collected'] >= 1) {
            $transfers[] = [
                'from'   => $info['name'],
                'to'     => $leaderName,
                'amount' => (int)round($info['collected']),
                'stage'  => 'concentrate',
            ];
        }
    }
    foreach ($byMember as $mid => $info) {
        if ((int)$mid !== (int)($trip['leader_id'] ?? 0) && $info['fair'] >= 1) {
            $transfers[] = [
                'from'   => $leaderName,
                'to'     => $info['name'],
                'amount' => (int)round($info['fair']),
                'stage'  => 'distribute',
            ];
        }
    }
}

// detalhamento por pessoa: pra quem cada um paga / de quem cada um recebe
$paysTo       = [];
$receivesFrom = [];
foreach ($transfers as $t) {
    $paysTo[$t['from']][]       = $t;
    $receivesFrom[$t['to']][]   = $t;
}
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
                    <th>Chaves</th>
                    <th>Com as keys</th>
                    <th>Cota</th>
                    <th>Movimentação</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($byMember as $mid => $info): ?>
                    <tr>
                        <td>
                            <?= (int)$mid === (int)($trip['leader_id'] ?? 0) ? '👑 ' : '' ?><?= e($info['name']) ?>
                            <?php if (!empty($info['joined_at'])): ?>
                                <span class="muted joined-late" title="Entrou depois: só divide os kills a partir daí">entrou <?= e(substr($info['joined_at'], 11, 5)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $info['keys'] ?></td>
                        <td class="gp" title="Valor líquido após 10% do G.E."><?= format_gp($info['collected']) ?></td>
                        <td class="gp" title="<?= e(format_gp_full($info['fair'])) ?>"><?= format_gp($info['fair']) ?></td>
                        <td>
                            <?php if (!$leaderName): ?>
                                <span class="muted">defina o líder</span>
                            <?php elseif (empty($paysTo[$info['name']]) && empty($receivesFrom[$info['name']])): ?>
                                <span class="muted">sem transferência</span>
                            <?php else: ?>
                                <?php foreach ($paysTo[$info['name']] ?? [] as $t): ?>
                                    <span class="flow-line">→ envia <span class="gp"><?= format_gp($t['amount']) ?></span> para <strong><?= e($t['to']) ?></strong></span>
                                <?php endforeach; ?>
                                <?php foreach ($receivesFrom[$info['name']] ?? [] as $t): ?>
                                    <span class="flow-line">← recebe <span class="gp"><?= format_gp($t['amount']) ?></span> de <strong><?= e($t['from']) ?></strong></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
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
                            <span class="muted"><?= $t['stage'] === 'concentrate' ? 'Concentrar:' : 'Distribuir:' ?></span>
                            <strong><?= e($t['from']) ?></strong> paga
                            <span class="gp" title="<?= e(format_gp_full($t['amount'])) ?>"><?= format_gp($t['amount']) ?></span>
                            para <strong><?= e($t['to']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="hint">1º as keys líquidas vão para o líder · 2º o líder distribui as cotas.</p>
            <?php endif; ?>
        </section>
    </div>

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
