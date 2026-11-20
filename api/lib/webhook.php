<?php
/**
 * চাষা ভাই — optional outbound webhooks (no third-party service required).
 *
 * When WEBHOOK_URL is set, the API POSTs a small JSON event, signed with
 * HMAC-SHA256 (header X-Chasha-Signature: sha256=<hex> over the raw body, using WEBHOOK_SECRET).
 * Events:
 *   outbreak.cell_alert  — a k-anonymous outbreak cell (≥3 reports) becomes ACTIVE or RISING
 * Each (event, cell, alert level, day) is sent at most once (dedupe in the store).
 * Payloads carry only published, aggregated data: grid-cell centre, counts, top disease labels.
 * Delivery is best-effort with a short timeout so a slow receiver never delays the farmer.
 */
declare(strict_types=1);
require_once __DIR__ . '/store.php';

function webhook_enabled(): bool { return filter_var((string)cfg('WEBHOOK_URL'), FILTER_VALIDATE_URL) !== false; }

function webhook_sign(string $body): string {
    return 'sha256=' . hash_hmac('sha256', $body, (string)cfg('WEBHOOK_SECRET'));
}

/** Send one event. Returns HTTP code (0 on failure / disabled). */
function webhook_send(string $event, array $data): int {
    if (!webhook_enabled()) return 0;
    $body = json_encode(['event' => $event, 'sent_at' => date('c'), 'source' => 'chasha-bhai', 'version' => CB_VERSION, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init((string)cfg('WEBHOOK_URL'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ChashaBhai-Webhook/' . CB_VERSION, 'X-Chasha-Event: ' . $event, 'X-Chasha-Signature: ' . webhook_sign((string)$body)],
        CURLOPT_POSTFIELDS => $body]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code;
}

/** After a new outbreak report: alert once per cell/level/day when a published cell is ACTIVE or RISING. */
function webhook_outbreak_check(float $lat, float $lon): ?int {
    if (!webhook_enabled() || !function_exists('outbreak_aggregate')) return null;
    [$clat, $clon] = outbreak_cell($lat, $lon);
    foreach (outbreak_aggregate(21)['cells'] as $c) {
        if (abs($c['lat'] - $clat) > 1e-6 || abs($c['lon'] - $clon) > 1e-6) continue;
        if (!in_array($c['alert'], ['ACTIVE', 'RISING'], true)) return null;
        $key = 'outbreak.cell_alert|' . $c['lat'] . ',' . $c['lon'] . '|' . $c['alert'] . '|' . date('Y-m-d');
        if (!store_webhook_once($key)) return null;   // already sent today
        return webhook_send('outbreak.cell_alert', ['cell' => ['lat' => $c['lat'], 'lon' => $c['lon'], 'size_deg' => OUTBREAK_CELL_DEG],
            'alert' => $c['alert'], 'reports_21d' => $c['n'], 'reports_7d' => $c['last7'], 'top' => $c['top'], 'k_anonymity' => OUTBREAK_K_ANON]);
    }
    return null;
}
