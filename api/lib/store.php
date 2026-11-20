<?php
/**
 * চাষা ভাই — small relational store for anonymous app data (SQLite via PHP PDO).
 *
 * Tables
 *   diagnoses : one row per photo diagnosis (NO photo, NO IP, NO exact GPS — only a 0.1° grid cell)
 *   feedback  : farmer's verdict on a diagnosis (correct / wrong / unsure + optional correct label)
 *   webhook_log : which outbound webhook events were already sent (dedupe)
 *
 * The database file lives in data/runtime/ (git-ignored, blocked from the web).
 * If pdo_sqlite is not available on the host, the same API falls back to
 * append-only JSON-lines files under data/runtime/store/ so nothing breaks.
 * Retention: rows older than STORE_RETENTION_DAYS (default 180) are deleted by store_sweep().
 */
declare(strict_types=1);

const STORE_VERDICTS = ['correct', 'wrong', 'unsure'];

function store_driver(): string {
    static $drv = null;
    if ($drv !== null) return $drv;
    $want = strtolower((string)(cfg('STORE_DRIVER') ?: 'auto'));
    $has = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    $drv = ($want === 'json' || !$has) ? 'json' : 'sqlite';
    return $drv;
}

function store_db(): ?PDO {
    static $pdo = null;
    if (store_driver() !== 'sqlite') return null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite:' . cb_data_dir() . '/chasha.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=3000;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS diagnoses (
        id TEXT PRIMARY KEY, created_at TEXT NOT NULL, crop TEXT, disease TEXT, category TEXT,
        photo_conf REAL, final_conf REAL, agreement TEXT, escalated INTEGER,
        weather_used INTEGER, satellite_used INTEGER, cell_lat REAL, cell_lon REAL, latency_ms INTEGER)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS feedback (
        id INTEGER PRIMARY KEY AUTOINCREMENT, diagnosis_id TEXT NOT NULL, created_at TEXT NOT NULL,
        verdict TEXT NOT NULL CHECK (verdict IN (\'correct\',\'wrong\',\'unsure\')), correct_label TEXT,
        UNIQUE(diagnosis_id), FOREIGN KEY (diagnosis_id) REFERENCES diagnoses(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS webhook_log (event_key TEXT PRIMARY KEY, sent_at TEXT NOT NULL, http_code INTEGER)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_diag_created ON diagnoses(created_at)');
    return $pdo;
}

// ------------------------------------------------------------------ JSON-lines fallback
function store_json_file(string $table): string { return cb_data_dir('store') . "/$table.jsonl"; }
function store_json_rows(string $table): array {
    $f = store_json_file($table); $rows = [];
    if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) { $r = json_decode($l, true); if (is_array($r)) $rows[] = $r; }
    return $rows;
}
function store_json_append(string $table, array $row): void {
    @file_put_contents(store_json_file($table), json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

// ------------------------------------------------------------------ public API
/** Log an anonymous diagnosis; returns its id (used by the feedback button). */
function store_add_diagnosis(array $r): string {
    $id = bin2hex(random_bytes(8));
    $cell = [null, null];
    if (isset($r['lat'], $r['lon']) && $r['lat'] !== null && function_exists('outbreak_cell')) $cell = outbreak_cell((float)$r['lat'], (float)$r['lon']);
    $row = ['id' => $id, 'created_at' => date('c'), 'crop' => mb_substr((string)($r['crop'] ?? ''), 0, 40),
        'disease' => mb_substr((string)($r['disease'] ?? ''), 0, 80), 'category' => mb_substr((string)($r['category'] ?? ''), 0, 20),
        'photo_conf' => round((float)($r['photo_conf'] ?? 0), 3), 'final_conf' => round((float)($r['final_conf'] ?? 0), 3),
        'agreement' => mb_substr((string)($r['agreement'] ?? ''), 0, 30), 'escalated' => !empty($r['escalated']) ? 1 : 0,
        'weather_used' => !empty($r['weather_used']) ? 1 : 0, 'satellite_used' => !empty($r['satellite_used']) ? 1 : 0,
        'cell_lat' => $cell[0], 'cell_lon' => $cell[1], 'latency_ms' => (int)($r['latency_ms'] ?? 0)];
    if ($db = store_db()) {
        $cols = array_keys($row);
        $st = $db->prepare('INSERT INTO diagnoses (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')');
        $st->execute($row);
    } else store_json_append('diagnoses', $row);
    return $id;
}

function store_get_diagnosis(string $id): ?array {
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    if ($db = store_db()) {
        $st = $db->prepare('SELECT * FROM diagnoses WHERE id = ?'); $st->execute([$id]);
        $r = $st->fetch(); return $r ?: null;
    }
    foreach (store_json_rows('diagnoses') as $r) if (($r['id'] ?? '') === $id) return $r;
    return null;
}

/** Save (or replace) the farmer's verdict. Returns ['ok'=>bool, 'error'?=>string]. */
function store_add_feedback(string $diag_id, string $verdict, string $label = ''): array {
    if (!in_array($verdict, STORE_VERDICTS, true)) return ['ok' => false, 'error' => 'verdict must be correct, wrong or unsure'];
    if (!store_get_diagnosis($diag_id)) return ['ok' => false, 'error' => 'unknown diagnosis_id'];
    $row = ['diagnosis_id' => $diag_id, 'created_at' => date('c'), 'verdict' => $verdict, 'correct_label' => $label !== '' ? mb_substr($label, 0, 80) : null];
    if ($db = store_db()) {
        $st = $db->prepare('INSERT INTO feedback (diagnosis_id, created_at, verdict, correct_label) VALUES (:diagnosis_id, :created_at, :verdict, :correct_label)
            ON CONFLICT(diagnosis_id) DO UPDATE SET created_at = excluded.created_at, verdict = excluded.verdict, correct_label = excluded.correct_label');
        $st->execute($row);
    } else store_json_append('feedback', $row);
    return ['ok' => true];
}

/** Joined, anonymous rows: one per diagnosis with its latest verdict (if any). */
function store_labelled_rows(int $days = 180, bool $only_labelled = false): array {
    $since = date('c', strtotime("-$days days"));
    if ($db = store_db()) {
        $sql = 'SELECT d.id, d.created_at, d.crop, d.disease, d.category, d.photo_conf, d.final_conf, d.agreement, d.escalated,
                       d.weather_used, d.satellite_used, f.verdict, f.correct_label
                FROM diagnoses d LEFT JOIN feedback f ON f.diagnosis_id = d.id
                WHERE d.created_at >= ?' . ($only_labelled ? ' AND f.verdict IS NOT NULL' : '') . ' ORDER BY d.created_at';
        $st = $db->prepare($sql); $st->execute([$since]); return $st->fetchAll();
    }
    $fb = [];
    foreach (store_json_rows('feedback') as $f) $fb[$f['diagnosis_id']] = $f; // last one wins
    $out = [];
    foreach (store_json_rows('diagnoses') as $d) {
        if (($d['created_at'] ?? '') < $since) continue;
        $f = $fb[$d['id']] ?? null;
        if ($only_labelled && !$f) continue;
        unset($d['cell_lat'], $d['cell_lon'], $d['latency_ms']);
        $out[] = $d + ['verdict' => $f['verdict'] ?? null, 'correct_label' => $f['correct_label'] ?? null];
    }
    return $out;
}

/** Aggregate stats — safe to publish (counts only). */
function store_stats(int $days = 90): array {
    $rows = store_labelled_rows($days);
    $s = ['window_days' => $days, 'diagnoses' => count($rows), 'with_feedback' => 0, 'verdicts' => ['correct' => 0, 'wrong' => 0, 'unsure' => 0],
        'farmer_agreement_rate' => null, 'by_crop' => [], 'escalated' => 0, 'storage' => store_driver()];
    foreach ($rows as $r) {
        if ((int)$r['escalated']) $s['escalated']++;
        $c = $r['crop'] ?: 'unknown';
        $s['by_crop'][$c] ??= ['diagnoses' => 0, 'correct' => 0, 'wrong' => 0];
        $s['by_crop'][$c]['diagnoses']++;
        if (!empty($r['verdict'])) {
            $s['with_feedback']++; $s['verdicts'][$r['verdict']]++;
            if (isset($s['by_crop'][$c][$r['verdict']])) $s['by_crop'][$c][$r['verdict']]++;
        }
    }
    $decided = $s['verdicts']['correct'] + $s['verdicts']['wrong'];
    if ($decided > 0) $s['farmer_agreement_rate'] = round($s['verdicts']['correct'] / $decided, 3);
    $s['note'] = 'Farmer self-reported verdicts; not a verified accuracy figure. See eval/EVAL.md for the reference-photo test.';
    return $s;
}

/** Was this webhook event already sent? If not, remember it. Returns true when it is NEW. */
function store_webhook_once(string $key, int $http_code = 0): bool {
    if ($db = store_db()) {
        $st = $db->prepare('INSERT OR IGNORE INTO webhook_log (event_key, sent_at, http_code) VALUES (?, ?, ?)');
        $st->execute([$key, date('c'), $http_code]);
        return $st->rowCount() === 1;
    }
    foreach (store_json_rows('webhook_log') as $r) if (($r['event_key'] ?? '') === $key) return false;
    store_json_append('webhook_log', ['event_key' => $key, 'sent_at' => date('c'), 'http_code' => $http_code]);
    return true;
}

/** Retention: delete rows older than STORE_RETENTION_DAYS (default 180). */
function store_sweep(): int {
    $days = (int)(cfg('STORE_RETENTION_DAYS') ?: 180);
    $cut = date('c', strtotime("-$days days")); $n = 0;
    if ($db = store_db()) {
        $db->exec('PRAGMA foreign_keys = ON');
        $st = $db->prepare('DELETE FROM feedback WHERE diagnosis_id IN (SELECT id FROM diagnoses WHERE created_at < ?)'); $st->execute([$cut]); $n += $st->rowCount();
        $st = $db->prepare('DELETE FROM diagnoses WHERE created_at < ?'); $st->execute([$cut]); $n += $st->rowCount();
        $st = $db->prepare('DELETE FROM webhook_log WHERE sent_at < ?'); $st->execute([$cut]); $n += $st->rowCount();
        return $n;
    }
    foreach (['diagnoses' => 'created_at', 'feedback' => 'created_at', 'webhook_log' => 'sent_at'] as $t => $col) {
        $keep = []; foreach (store_json_rows($t) as $r) { if (($r[$col] ?? '') >= $cut) $keep[] = $r; else $n++; }
        @file_put_contents(store_json_file($t), $keep ? implode("\n", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $keep)) . "\n" : '', LOCK_EX);
    }
    return $n;
}
