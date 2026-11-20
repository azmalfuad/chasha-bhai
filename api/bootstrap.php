<?php
/**
 * চাষা ভাই v2 — shared bootstrap (config, security headers, CORS, rate limit, Grok client)
 *
 * SECRETS: nothing secret lives in this file or anywhere in the repo.
 * Settings are read, in order of priority, from:
 *   1. environment variables (GROK_API_KEY, ALLOWED_ORIGINS, SAT_SERVICE_URL, ...)
 *   2. a PHP file OUTSIDE the web root:  <web root>/../chasha_config.php
 *   3. api/config.local.php  (git-ignored, blocked from the web by api/.htaccess)
 * See api/config.sample.php for every option.
 */
declare(strict_types=1);

const CB_VERSION = '2.0.0';
date_default_timezone_set('Asia/Dhaka');
ini_set('display_errors', '0');

function cb_load_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $defaults = [
        'GROK_API_KEY'       => '',
        'GROK_API_URL'       => 'https://api.x.ai/v1/chat/completions',
        'GROK_VISION_MODEL'  => 'grok-4.20-non-reasoning',  // image -> structured diagnosis
        'GROK_FAST_MODEL'    => 'grok-4.20-non-reasoning',  // cheap, short Bangla advisories
        'GROK_REASON_MODEL'  => 'grok-4.3',                 // Krishi Copilot (RAG answers)
        'GROK_TIMEOUT'       => 90,
        'GROK_SSL_VERIFY'    => true,
        'DEBUG'              => false,
        'ALLOWED_ORIGINS'    => '',        // comma list; empty = same-origin only
        'SAT_SERVICE_URL'    => '',        // e.g. https://chasha-sat.onrender.com
        'RATE_LIMIT_PER_MIN' => 20,        // per IP, per endpoint bucket
        'AI_RATE_LIMIT_PER_DAY' => 60,     // per IP for paid AI calls
        'AI_GLOBAL_PER_DAY'  => 0,         // all-visitor AI calls per day (0 = off); set on public demos
        'TRUST_LOCAL_PROXY'  => false,     // true only when a tunnel runs on the same host
        'MAX_IMAGE_BYTES'    => 8 * 1024 * 1024,
        'DATA_DIR'           => dirname(__DIR__) . '/data/runtime',
        // --- data layer add-ons (all optional; no paid service, no new provider key)
        'STORE_DRIVER'       => 'auto',    // auto = SQLite (PDO) if available, else JSON-lines; or 'json'
        'STORE_RETENTION_DAYS' => 180,     // anonymous diagnosis/feedback rows are deleted after this
        'ANSWER_CACHE_TTL'   => 10800,     // advice-chat answer cache (seconds); 0 = off
        'WEBHOOK_URL'        => '',        // optional outbound webhook (e.g. an extension office endpoint)
        'WEBHOOK_SECRET'     => '',        // HMAC-SHA256 signing secret for X-Chasha-Signature
        'EXPORT_TOKEN'       => '',        // required for the row-level labelled-dataset export; empty = disabled
    ];
    $file = [];
    foreach ([dirname(__DIR__, 2) . '/chasha_config.php', __DIR__ . '/config.local.php'] as $p) {
        if (is_readable($p)) { $v = include $p; if (is_array($v)) { $file = $v; break; } }
    }
    $cfg = $defaults;
    foreach ($defaults as $k => $d) {
        if (array_key_exists($k, $file)) $cfg[$k] = $file[$k];
        $env = getenv($k);
        if ($env !== false && $env !== '') $cfg[$k] = $env;
        if (is_bool($d)) $cfg[$k] = filter_var($cfg[$k], FILTER_VALIDATE_BOOLEAN);
        if (is_int($d))  $cfg[$k] = (int)$cfg[$k];
    }
    return $cfg;
}

function cfg(string $k) { return cb_load_config()[$k] ?? null; }

function cb_data_dir(string $sub = ''): string {
    $d = rtrim((string)cfg('DATA_DIR'), '/') . ($sub ? "/$sub" : '');
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

// ------------------------------------------------------------------ headers / CORS
function send_common_headers(string $methods = 'GET, POST, OPTIONS'): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', (string)cfg('ALLOWED_ORIGINS'))));
    if ($origin && (in_array($origin, $allowed, true) || in_array('*', $allowed, true))) {
        header('Access-Control-Allow-Origin: ' . (in_array('*', $allowed, true) ? '*' : $origin));
        header('Vary: Origin');
        header("Access-Control-Allow-Methods: $methods");
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function client_ip(): string {
    // REMOTE_ADDR only: X-Forwarded-For is trivially spoofable on shared hosting.
    $ra = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Exception: behind a LOCAL tunnel (cloudflared on the same machine) every request comes from
    // 127.0.0.1; trust CF-Connecting-IP only then, and only when TRUST_LOCAL_PROXY is enabled.
    if (cfg('TRUST_LOCAL_PROXY') && in_array($ra, ['127.0.0.1', '::1'], true)) {
        $h = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($h !== '' && filter_var($h, FILTER_VALIDATE_IP)) return $h;
    }
    return $ra;
}

// ------------------------------------------------------------------ rate limiting (file based)
function rate_limit(string $bucket, int $limit, int $window_sec): void {
    $dir = cb_data_dir('ratelimit');
    $key = hash('sha256', $bucket . '|' . client_ip() . '|' . floor(time() / $window_sec));
    $f = "$dir/$key";
    $fp = @fopen($f, 'c+');
    if (!$fp) return; // fail open (never lock farmers out because of a disk issue)
    flock($fp, LOCK_EX);
    $n = (int)stream_get_contents($fp) + 1;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)$n);
    flock($fp, LOCK_UN); fclose($fp);
    if (mt_rand(1, 200) === 1) cb_gc($dir, 2 * 86400);
    if ($n > $limit) {
        header('Retry-After: ' . $window_sec);
        json_error(429, 'অনেক বেশি অনুরোধ। কিছুক্ষণ পর আবার চেষ্টা করুন। (Too many requests)');
    }
    // Global (all visitors) daily AI budget — protects the operator's xAI bill on a public demo.
    if ($bucket === 'ai-day' && !ai_global_budget_ok()) {
        header('Retry-After: 3600');
        json_error(429, 'আজকের এআই ব্যবহারের সীমা শেষ। আগামীকাল আবার চেষ্টা করুন। (Daily AI budget reached)');
    }
}

/** Counts one AI call against AI_GLOBAL_PER_DAY (0 = unlimited). Returns false when exhausted. */
function ai_global_budget_ok(): bool {
    $cap = (int)cfg('AI_GLOBAL_PER_DAY');
    if ($cap <= 0) return true;
    $f = cb_data_dir('ratelimit') . '/global-ai-' . date('Y-m-d');
    $fp = @fopen($f, 'c+'); if (!$fp) return true;
    flock($fp, LOCK_EX); $n = (int)stream_get_contents($fp) + 1;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)$n); flock($fp, LOCK_UN); fclose($fp);
    return $n <= $cap;
}

function cb_gc(string $dir, int $max_age): void {
    foreach (glob("$dir/*") ?: [] as $f) if (is_file($f) && filemtime($f) < time() - $max_age) @unlink($f);
}

// ------------------------------------------------------------------ JSON helpers
function json_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function json_error(int $code, string $message, $extra = null): void {
    $out = ['error' => $message];
    if ($extra !== null && cfg('DEBUG')) $out['details'] = $extra;   // details only in debug
    json_out($out, $code);
}

function read_json_body(int $max_bytes): array {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max_bytes) json_error(413, 'অনুরোধ অনেক বড়। (Request too large)');
    $raw = file_get_contents('php://input', false, null, 0, $max_bytes + 1);
    if ($raw === false || strlen($raw) > $max_bytes) json_error(413, 'অনুরোধ অনেক বড়। (Request too large)');
    $d = json_decode($raw ?: '{}', true);
    if (!is_array($d)) json_error(400, 'Invalid JSON body.');
    return $d;
}

function extract_json(string $content): ?array {
    $clean = trim($content);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/\s*```$/', '', (string)$clean);
    $first = strpos((string)$clean, '{'); $last = strrpos((string)$clean, '}');
    if ($first !== false && $last !== false && $last > $first) $clean = substr($clean, $first, $last - $first + 1);
    $d = json_decode((string)$clean, true);
    return is_array($d) ? $d : null;
}

function clean_text(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

// ------------------------------------------------------------------ simple file cache
function cache_get(string $ns, string $key, int $ttl) {
    $f = cb_data_dir("cache/$ns") . '/' . hash('sha256', $key) . '.json';
    if (is_file($f) && filemtime($f) > time() - $ttl) { $d = json_decode((string)file_get_contents($f), true); if ($d !== null) return $d; }
    return null;
}
function cache_put(string $ns, string $key, $val): void {
    $f = cb_data_dir("cache/$ns") . '/' . hash('sha256', $key) . '.json';
    @file_put_contents($f, json_encode($val, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ------------------------------------------------------------------ HTTP (public APIs)
function http_get_json(string $url, int $timeout = 15): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ChashaBhai/' . CB_VERSION],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false || $code !== 200) return null;
    $d = json_decode((string)$r, true);
    return is_array($d) ? $d : null;
}

// ------------------------------------------------------------------ Grok (xAI)
function grok_available(): bool { return (string)cfg('GROK_API_KEY') !== ''; }

/**
 * Call xAI chat completions. $schema (optional) enables Structured Outputs
 * (response_format json_schema, strict) so we never have to "hope" for JSON.
 */
function grok_call(array $payload, ?array $schema = null, string $schema_name = 'out'): array {
    if (!grok_available()) return ['ok' => false, 'http_code' => 0, 'error' => 'GROK_API_KEY not configured'];
    if ($schema) $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => $schema_name, 'strict' => true, 'schema' => $schema]];
    $ch = curl_init((string)cfg('GROK_API_URL'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => (int)cfg('GROK_TIMEOUT'), CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('GROK_API_KEY'), 'User-Agent: ChashaBhai/' . CB_VERSION],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_SSL_VERIFYPEER => (bool)cfg('GROK_SSL_VERIFY'),
        CURLOPT_SSL_VERIFYHOST => cfg('GROK_SSL_VERIFY') ? 2 : 0,
    ];
    curl_setopt_array($ch, $opts);
    $t0 = microtime(true);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); $errno = curl_errno($ch);
    curl_close($ch);
    $ms = (int)((microtime(true) - $t0) * 1000);
    if ($resp === false || $errno) return ['ok' => false, 'http_code' => $code, 'error' => "cURL ($errno): $err", 'ms' => $ms];
    $data = json_decode((string)$resp, true);
    if ($code !== 200) return ['ok' => false, 'http_code' => $code, 'error' => $data['error']['message'] ?? ($data['error'] ?? "HTTP $code"), 'ms' => $ms];
    $usage = $data['usage'] ?? [];
    cb_log_usage($payload['model'] ?? '?', $usage, $ms);
    return ['ok' => true, 'content' => $data['choices'][0]['message']['content'] ?? '', 'model' => $data['model'] ?? ($payload['model'] ?? ''), 'usage' => $usage, 'ms' => $ms];
}

/** Append token usage to a daily JSONL (observability / cost tracking, no user content). */
function cb_log_usage(string $model, array $usage, int $ms): void {
    $line = json_encode(['t' => date('c'), 'ep' => basename($_SERVER['SCRIPT_NAME'] ?? ''), 'model' => $model,
        'in' => $usage['prompt_tokens'] ?? null, 'cached' => $usage['prompt_tokens_details']['cached_tokens'] ?? null,
        'out' => $usage['completion_tokens'] ?? null, 'ms' => $ms]);
    @file_put_contents(cb_data_dir('logs') . '/usage-' . date('Y-m-d') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

function bn_digits($s): string { return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']); }

function require_method(string $m): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $m) json_error(405, "Method not allowed. Use $m.");
}

function in_bangladesh(float $lat, float $lon): bool { return $lat >= 20.5 && $lat <= 26.8 && $lon >= 87.9 && $lon <= 92.8; }

/**
 * Data retention (runs on ~1% of requests, cheap): delete outbreak month-files older than
 * OUTBREAK_RETENTION_DAYS (default 180), usage logs older than 30 days, rate-limit buckets
 * older than 2 days and cache files older than 8 days.
 */
function cb_retention_sweep(): void {
    $now = time();
    $rules = [
        ['outbreak', 'reports-*.jsonl', (int)(cfg('OUTBREAK_RETENTION_DAYS') ?: 180) * 86400],
        ['logs', 'usage-*.jsonl', 30 * 86400],
        ['ratelimit', '*', 2 * 86400],
    ];
    foreach ($rules as [$ns, $pat, $age]) {
        foreach (glob(cb_data_dir($ns) . '/' . $pat) ?: [] as $f) if (is_file($f) && $now - filemtime($f) > $age) @unlink($f);
    }
    foreach (glob(cb_data_dir('cache') . '/*/*') ?: [] as $f) if (is_file($f) && $now - filemtime($f) > 8 * 86400) @unlink($f);
    // anonymous diagnosis / feedback rows (SQLite or JSON-lines fallback)
    if (is_file(__DIR__ . '/lib/store.php')) { require_once __DIR__ . '/lib/store.php'; store_sweep(); }
}
if (random_int(1, 100) === 1) { try { cb_retention_sweep(); } catch (Throwable $e) {} }
