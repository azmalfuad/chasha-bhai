<?php
/** GET /api/status.php — live system status for the /docs dashboard. Never reveals secrets. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/kb.php';
require_once __DIR__ . '/lib/outbreak_store.php';
send_common_headers('GET, OPTIONS');
require_method('GET');
rate_limit('status', 30, 60);

function probe(string $name, string $url, array $headers = [], int $timeout = 8): array {
    if ($hit = cache_get('status', $name, 120)) return $hit + ['cached' => true];
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_NOBODY => false]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $r = ['ok' => $code >= 200 && $code < 300, 'http' => $code, 'ms' => (int)((microtime(true) - $t0) * 1000), 'checked_at' => date('c')];
    cache_put('status', $name, $r);
    return $r;
}

$checks = [
    'open_meteo' => probe('open_meteo', 'https://api.open-meteo.com/v1/forecast?latitude=23.81&longitude=90.41&current=temperature_2m'),
    'ai_service' => grok_available() ? probe('ai', 'https://api.x.ai/v1/models', ['Authorization: Bearer ' . cfg('GROK_API_KEY')]) : ['ok' => false, 'error' => 'GROK_API_KEY not set'],
    'satellite_service' => cfg('SAT_SERVICE_URL') ? probe('sat', rtrim((string)cfg('SAT_SERVICE_URL'), '/') . '/health', [], 70) : ['ok' => false, 'error' => 'SAT_SERVICE_URL not set'],
    'earth_search_stac' => probe('stac', 'https://earth-search.aws.element84.com/v1/collections/sentinel-2-l2a'),
];

$usage = ['calls' => 0, 'in' => 0, 'out' => 0, 'cached' => 0, 'avg_ms' => 0, 'by_endpoint' => []];
$f = cb_data_dir('logs') . '/usage-' . date('Y-m-d') . '.jsonl'; $ms = 0;
if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $r = json_decode($l, true); if (!$r) continue;
    $usage['calls']++; $usage['in'] += (int)$r['in']; $usage['out'] += (int)$r['out']; $usage['cached'] += (int)$r['cached']; $ms += (int)$r['ms'];
    $usage['by_endpoint'][$r['ep']] = ($usage['by_endpoint'][$r['ep']] ?? 0) + 1;
}
$usage['avg_ms'] = $usage['calls'] ? (int)($ms / $usage['calls']) : 0;
$usage['cache_hit_ratio'] = $usage['in'] ? round($usage['cached'] / $usage['in'], 2) : null;

$ob = outbreak_aggregate(21);
json_out([
    'service' => 'চাষা ভাই API', 'version' => CB_VERSION, 'time' => date('c'), 'php' => PHP_VERSION,
    'config' => ['ai_key_set' => grok_available(), 'ssl_verify' => (bool)cfg('GROK_SSL_VERIFY'), 'debug' => (bool)cfg('DEBUG'),
        'models' => ['vision' => 'চাষা ভাই AI', 'fast' => 'চাষা ভাই AI', 'reasoning' => 'চাষা ভাই AI'],
        'cors_origins' => cfg('ALLOWED_ORIGINS') ?: 'same-origin only', 'satellite_service' => cfg('SAT_SERVICE_URL') ? 'configured' : 'not configured',
        'rate_limit_per_min' => cfg('RATE_LIMIT_PER_MIN'), 'ai_calls_per_ip_per_day' => cfg('AI_RATE_LIMIT_PER_DAY')],
    'checks' => $checks,
    'knowledge_base' => ['docs' => count(kb_docs())],
    'community' => ['reports_21d' => $ob['total_reports'], 'published_cells' => count($ob['cells']), 'suppressed_cells' => $ob['suppressed_cells']],
    'ai_usage_today' => $usage,
]);
