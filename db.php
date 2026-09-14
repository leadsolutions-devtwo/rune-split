<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

/**
 * Com DATABASE_URL definida (produção/Render) usa PostgreSQL;
 * sem ela (XAMPP local) usa SQLite — nenhuma configuração necessária.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $url = getenv('DATABASE_URL') ?: '';

    if ($url !== '') {
        $p   = parse_url($url);
        $host = str_replace('-pooler.', '.', $p['host'] ?? '');
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '', '/')
        );
        parse_str($p['query'] ?? '', $q);
        if (!empty($q['sslmode'])) {
            $dsn .= ';sslmode=' . $q['sslmode'];
        }
        $pdo = new PDO($dsn, rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? ''));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");

        $schema = [
            "CREATE TABLE IF NOT EXISTS trips (
                id SERIAL PRIMARY KEY,
                name TEXT NOT NULL,
                leader_id INTEGER,
                closed_at TIMESTAMP(0),
                password_hash TEXT,
                api_token TEXT,
                created_at TIMESTAMP(0) NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS members (
                id SERIAL PRIMARY KEY,
                trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                joined_at TIMESTAMP(0),
                removed_at TIMESTAMP(0)
            )",
            "CREATE TABLE IF NOT EXISTS kills (
                id SERIAL PRIMARY KEY,
                trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
                value BIGINT NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                created_at TIMESTAMP(0) NOT NULL DEFAULT NOW()
            )",
            "CREATE TABLE IF NOT EXISTS member_breaks (
                id SERIAL PRIMARY KEY,
                member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
                left_at TIMESTAMP(0) NOT NULL,
                returned_at TIMESTAMP(0)
            )",
        ];
    } else {
        $pdo = new PDO('sqlite:' . __DIR__ . '/rune.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $schema = [
            "CREATE TABLE IF NOT EXISTS trips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                leader_id INTEGER,
                closed_at TEXT,
                password_hash TEXT,
                api_token TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )",
            "CREATE TABLE IF NOT EXISTS members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                joined_at TEXT,
                removed_at TEXT
            )",
            "CREATE TABLE IF NOT EXISTS kills (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
                value INTEGER NOT NULL,
                note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )",
            "CREATE TABLE IF NOT EXISTS member_breaks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
                left_at TEXT NOT NULL,
                returned_at TEXT
            )",
        ];
    }

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }

    // migração de bancos criados antes de líder/fechamento/entrada tardia
    $isPg = $url !== '';
    if ($isPg) {
        $pdo->exec('ALTER TABLE trips ADD COLUMN IF NOT EXISTS leader_id INTEGER');
        $pdo->exec('ALTER TABLE trips ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP(0)');
        $pdo->exec('ALTER TABLE trips ADD COLUMN IF NOT EXISTS password_hash TEXT');
        $pdo->exec('ALTER TABLE trips ADD COLUMN IF NOT EXISTS api_token TEXT');
        $pdo->exec('ALTER TABLE members ADD COLUMN IF NOT EXISTS joined_at TIMESTAMP(0)');
        $pdo->exec('ALTER TABLE members ADD COLUMN IF NOT EXISTS removed_at TIMESTAMP(0)');
    } else {
        foreach ([
            'ALTER TABLE trips ADD COLUMN leader_id INTEGER',
            'ALTER TABLE trips ADD COLUMN closed_at TEXT',
            'ALTER TABLE trips ADD COLUMN password_hash TEXT',
            'ALTER TABLE trips ADD COLUMN api_token TEXT',
            'ALTER TABLE members ADD COLUMN joined_at TEXT',
            'ALTER TABLE members ADD COLUMN removed_at TEXT',
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // coluna já existe
            }
        }
    }

    return $pdo;
}

function e(mixed $s): string
{
    // nicks só de números viram int como chave de array no PHP, então aceita qualquer tipo
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Converte entrada estilo OSRS em gp inteiro.
 * Aceita: "500k", "1.2m", "1,2m", "2b", "750000", "1.200.000".
 * Retorna null se não conseguir interpretar.
 */
function parse_gp(string $s): ?int
{
    $s = strtolower(trim($s));
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^([\d.,\s]+)\s*([kmb]?)$/', $s, $m)) {
        return null;
    }
    $num    = str_replace(' ', '', $m[1]);
    $suffix = $m[2];

    if ($suffix !== '') {
        $num = str_replace(',', '.', $num);
        if (substr_count($num, '.') > 1 || !is_numeric($num)) {
            return null;
        }
        $mult  = ['k' => 1e3, 'm' => 1e6, 'b' => 1e9][$suffix];
        $value = (float)$num * $mult;
    } else {
        // sem sufixo: pontos e vírgulas são separadores de milhar
        $digits = preg_replace('/\D/', '', $num);
        if ($digits === '') {
            return null;
        }
        $value = (float)$digits;
    }

    if ($value < 1) {
        return null;
    }
    return (int)round($value);
}

/** Formata gp no estilo OSRS: 1.2M, 500K, 2B. */
function format_gp(int|float $gp): string
{
    $gp  = (int)round($gp);
    $abs = abs($gp);

    $fmt = function (float $n, string $suffix): string {
        $s = number_format($n, 2, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');
        return $s . $suffix;
    };

    if ($abs >= 1e9) {
        return $fmt($gp / 1e9, 'B');
    }
    if ($abs >= 1e6) {
        return $fmt($gp / 1e6, 'M');
    }
    if ($abs >= 1e3) {
        return $fmt($gp / 1e3, 'K');
    }
    return number_format($gp, 0, ',', '.');
}

/** gp por extenso, com separador de milhar. */
function format_gp_full(int|float $gp): string
{
    return number_format((int)round($gp), 0, ',', '.') . ' gp';
}

/**
 * Calcula as transferências para acertar o split.
 * $balances: [nome => saldo], saldo positivo = recebeu além da cota (deve pagar).
 * Retorna lista de ['from' => nome, 'to' => nome, 'amount' => gp].
 */
function settle(array $balances): array
{
    $debtors   = [];
    $creditors = [];
    foreach ($balances as $name => $b) {
        if ($b >= 1) {
            $debtors[] = ['name' => $name, 'amt' => $b];
        } elseif ($b <= -1) {
            $creditors[] = ['name' => $name, 'amt' => -$b];
        }
    }
    usort($debtors, fn($a, $b) => $b['amt'] <=> $a['amt']);
    usort($creditors, fn($a, $b) => $b['amt'] <=> $a['amt']);

    $transfers = [];
    $i = 0;
    $j = 0;
    while ($i < count($debtors) && $j < count($creditors)) {
        $pay = min($debtors[$i]['amt'], $creditors[$j]['amt']);
        if ($pay >= 1) {
            $transfers[] = [
                'from'   => $debtors[$i]['name'],
                'to'     => $creditors[$j]['name'],
                'amount' => (int)round($pay),
            ];
        }
        $debtors[$i]['amt']   -= $pay;
        $creditors[$j]['amt'] -= $pay;
        if ($debtors[$i]['amt'] < 1) {
            $i++;
        }
        if ($creditors[$j]['amt'] < 1) {
            $j++;
        }
    }
    return $transfers;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
