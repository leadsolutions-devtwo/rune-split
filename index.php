<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

if (empty($_SESSION['create_trip_token'])) {
    $_SESSION['create_trip_token'] = bin2hex(random_bytes(32));
}

$pdo   = db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_trip') {
        $submittedToken = (string)($_POST['create_trip_token'] ?? '');
        $expectedToken  = (string)($_SESSION['create_trip_token'] ?? '');
        if ($submittedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
            redirect('index.php');
        }
        // Cada formulário só pode criar uma trip, mesmo com vários cliques simultâneos.
        unset($_SESSION['create_trip_token']);

        $leader  = trim($_POST['leader'] ?? '');
        $name    = trim($_POST['name'] ?? '');
        $rawList = trim($_POST['members'] ?? '');
        $rawNames = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawList))));

        // líder entra primeiro; remove nomes repetidos (sem diferenciar maiúsculas)
        $names = [];
        $seen  = [];
        foreach (array_merge([$leader], $rawNames) as $n) {
            if ($n === '') {
                continue;
            }
            $key = mb_strtolower($n);
            if (!in_array($key, $seen, true)) {
                $names[] = $n;
                $seen[]  = $key;
            }
        }

        if ($leader === '') {
            $error = 'Informe o nome do líder.';
        } elseif ($name === '') {
            $error = 'Dá um nome pra trip.';
        } elseif (count($names) < 2) {
            $error = 'Coloca pelo menos 1 participante além do líder.';
        } else {
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO trips (name) VALUES (?) RETURNING id');
            $ins->execute([$name]);
            $tripId = (int)$ins->fetchColumn();
            $ins->closeCursor();

            $stmt     = $pdo->prepare('INSERT INTO members (trip_id, name) VALUES (?, ?) RETURNING id');
            $leaderId = null;
            foreach ($names as $i => $n) {
                $stmt->execute([$tripId, $n]);
                $memberId = (int)$stmt->fetchColumn();
                $stmt->closeCursor();
                if ($i === 0) {
                    $leaderId = $memberId; // o primeiro da lista lidera a trip
                }
            }
            $pdo->prepare('UPDATE trips SET leader_id = ? WHERE id = ?')->execute([$leaderId, $tripId]);
            $pdo->commit();
            redirect('trip.php?id=' . $tripId);
        }
    }

    if ($action === 'delete_trip') {
        $id = (int)($_POST['trip_id'] ?? 0);
        $pdo->prepare('DELETE FROM trips WHERE id = ?')->execute([$id]);
        redirect('index.php');
    }
}

if (empty($_SESSION['create_trip_token'])) {
    $_SESSION['create_trip_token'] = bin2hex(random_bytes(32));
}
$createTripToken = $_SESSION['create_trip_token'];

$trips = $pdo->query(
    "SELECT t.id, t.name, t.created_at, t.closed_at,
            (SELECT m.name FROM members m WHERE m.id = t.leader_id)          AS leader_name,
            (SELECT COUNT(*) FROM members m WHERE m.trip_id = t.id)          AS member_count,
            (SELECT COUNT(*) FROM kills k  WHERE k.trip_id = t.id)          AS kill_count,
            (SELECT COALESCE(SUM(k.value), 0) FROM kills k WHERE k.trip_id = t.id) AS total
     FROM trips t
     ORDER BY t.id DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rune Split — Trips</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='0.9em' font-size='90'%3E⚔️%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
    <div class="credits-banner" title="o lendário criador do app 👑">✨ AGRADEÇAM AO MATHEUS WAIF ✨</div>
    <header class="topbar">
        <h1>⚔️ Rune Split</h1>
        <p class="sub">Controle de loot keys e split das trips na Wilderness</p>
    </header>

    <?php if ($error): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Nova trip</h2>
        <form method="post" class="trip-form" novalidate>
            <input type="hidden" name="action" value="create_trip">
            <input type="hidden" name="create_trip_token" value="<?= e($createTripToken) ?>">
            <div class="form-cols">
                <div class="form-col">
                    <h3>👑 Líder &amp; trip</h3>
                    <label>
                        Seu nome (você será o líder)
                        <input type="text" name="leader" id="leader-input" placeholder="Ex: master" required
                               value="<?= e($_POST['leader'] ?? '') ?>">
                        <span class="hint" id="leader-hint">&nbsp;</span>
                    </label>
                    <label>
                        Nome da trip
                        <input type="text" name="name" id="name-input" placeholder="Ex: Revs 16/07" required
                               value="<?= e($_POST['name'] ?? '') ?>">
                        <span class="hint" id="name-hint">&nbsp;</span>
                    </label>
                </div>
                <div class="form-col">
                    <h3>⚔️ Participantes</h3>
                    <label class="grow">
                        Um por linha, sem contar você
                        <textarea name="members" id="members-input" rows="5" placeholder="Zezin&#10;PkMaster&#10;Fulano&#10;Ciclano" required><?= e($_POST['members'] ?? '') ?></textarea>
                        <span class="hint" id="members-hint">&nbsp;</span>
                    </label>
                </div>
            </div>
            <button type="submit" class="btn primary wide">Criar trip</button>
        </form>
    </section>

    <section class="card">
        <h2>Trips</h2>
        <?php if (!$trips): ?>
            <p class="muted">Nenhuma trip ainda. Cria a primeira aí em cima 👆</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Trip</th>
                    <th>Líder</th>
                    <th>Status</th>
                    <th>Membros</th>
                    <th>Kills</th>
                    <th>Líquido (-10% G.E.)</th>
                    <th>Criada em</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trips as $t): ?>
                    <tr>
                        <td><a class="trip-link" href="trip.php?id=<?= (int)$t['id'] ?>"><?= e($t['name']) ?></a></td>
                        <td><?= $t['leader_name'] ? '👑 ' . e($t['leader_name']) : '<span class="muted">—</span>' ?></td>
                        <td>
                            <?php if ($t['closed_at']): ?>
                                <span class="badge closed">🔒 fechada</span>
                            <?php else: ?>
                                <span class="badge open">🔓 aberta</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$t['member_count'] ?></td>
                        <td><?= (int)$t['kill_count'] ?></td>
                        <td class="gp"><?= format_gp((int)round((int)$t['total'] * 0.9)) ?></td>
                        <td class="muted"><?= e($t['created_at']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Apagar a trip &quot;<?= e($t['name']) ?>&quot; e todos os kills dela?')">
                                <input type="hidden" name="action" value="delete_trip">
                                <input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn danger small">apagar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<script>
// validação própria, sem o balão nativo do navegador
const form = document.querySelector('.trip-form');
const fields = {
    leader:  {el: document.getElementById('leader-input'),  hint: document.getElementById('leader-hint'),  msg: '❌ informe o nome do líder'},
    name:    {el: document.getElementById('name-input'),    hint: document.getElementById('name-hint'),    msg: '❌ dá um nome pra trip'},
    members: {el: document.getElementById('members-input'), hint: document.getElementById('members-hint'), msg: '❌ coloca pelo menos 1 participante além de você'},
};

form.addEventListener('submit', (ev) => {
    let bad = null;
    for (const key of ['leader', 'name', 'members']) {
        const f = fields[key];
        const empty = key === 'members'
            ? f.el.value.split(/[\n,]+/).map(s => s.trim()).filter(Boolean).length < 1
            : !f.el.value.trim();
        if (empty) {
            f.el.classList.add('invalid');
            f.hint.textContent = f.msg;
            f.hint.classList.add('err');
            if (!bad) bad = f.el;
        }
    }
    if (bad) {
        ev.preventDefault();
        bad.focus();
    } else {
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Criando...';
    }
});

for (const f of Object.values(fields)) {
    f.el.addEventListener('input', () => {
        f.el.classList.remove('invalid');
        f.hint.classList.remove('err');
        f.hint.innerHTML = '&nbsp;';
    });
}
</script>
<script src="assets/fx.js" defer></script>
</body>
</html>
