<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

/**
 * API pra plugins externos registrarem kills automaticamente.
 * Autenticação por token da trip (gerado na tela da trip), sem sessão/cookie.
 * Só grava o kill se o nome já for de um membro ativo da trip — quem não
 * está na trip é ignorado, não vira membro novo sozinho.
 *
 * Aceita dois formatos de corpo:
 * - Genérico: name, value (aceita "500k" etc.), note — form ou JSON.
 * - Webhook do plugin Dink (RuneLite): multipart com campo "payload_json",
 *   evento do tipo LOOT. O nome vem de playerName, o valor é a soma de
 *   quantity * priceEach dos itens, e a origem (source) vira a observação.
 *
 * O Dink só permite configurar a URL do webhook (sem headers/campos extra),
 * então o token pode vir também via querystring: api.php?token=...
 */

header('Content-Type: application/json; charset=utf-8');

function api_fail(int $status, string $error): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_skip(string $reason): never
{
    // requisição autenticada e válida, mas não virou kill (ex: drop de quem
    // não está na trip) — responde 200 pra não acender erro no plugin
    echo json_encode(['ok' => false, 'skipped' => $reason], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail(405, 'method_not_allowed');
}

$input = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$dink = null;
if (isset($_POST['payload_json'])) {
    $decoded = json_decode((string)$_POST['payload_json'], true);
    if (is_array($decoded)) {
        $dink = $decoded;
    }
}

$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $token = trim($m[1]);
} elseif (!empty($input['token'])) {
    $token = trim((string)$input['token']);
} else {
    $token = trim((string)($_GET['token'] ?? ''));
}
if ($token === '') {
    api_fail(401, 'missing_token');
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM trips WHERE api_token = ?');
$stmt->execute([$token]);
$trip = $stmt->fetch();
if (!$trip) {
    api_fail(401, 'invalid_token');
}
if (!empty($trip['closed_at'])) {
    api_skip('trip_closed');
}

if ($dink !== null) {
    if (($dink['type'] ?? '') !== 'LOOT') {
        api_skip('dink_event_not_loot');
    }
    $name  = trim((string)($dink['playerName'] ?? ''));
    $items = $dink['extra']['items'] ?? [];
    $value = 0;
    foreach ($items as $item) {
        $value += (int)($item['quantity'] ?? 0) * (int)($item['priceEach'] ?? 0);
    }
    $note = trim((string)($dink['extra']['source'] ?? ''));
} else {
    $name  = trim((string)($input['name'] ?? ''));
    $note  = trim((string)($input['note'] ?? ''));
    $raw   = (string)($input['value'] ?? '');
    $value = ctype_digit($raw) ? (int)$raw : parse_gp($raw);
}

if ($name === '') {
    api_skip('missing_name');
}
if ($value === null || $value < 1) {
    api_skip('invalid_value');
}

// espaço e "_" são a mesma coisa pro RuneScape (nick interno usa "_"), então
// aceita as duas formas pra não perder o match por causa disso
$stmt = $pdo->prepare(
    "SELECT id FROM members WHERE trip_id = ? AND removed_at IS NULL
     AND REPLACE(LOWER(name), ' ', '_') = REPLACE(LOWER(?), ' ', '_')"
);
$stmt->execute([$trip['id'], $name]);
$memberId = $stmt->fetchColumn();
if (!$memberId) {
    // nome não é de ninguém ativo nessa trip: ignora em silêncio (ex: drop de
    // outro clan mate que não faz parte dela), não cria membro novo sozinho
    api_skip('member_not_found');
}

$pdo->prepare('INSERT INTO kills (trip_id, member_id, value, note) VALUES (?, ?, ?, ?)')
    ->execute([$trip['id'], (int)$memberId, $value, $note]);

echo json_encode([
    'ok'        => true,
    'trip_id'   => (int)$trip['id'],
    'member_id' => (int)$memberId,
    'value'     => $value,
], JSON_UNESCAPED_UNICODE);
