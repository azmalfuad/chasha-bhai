<?php
/**
 * চাষা ভাই — কৃষি পরামর্শ over Server-Sent Events (streaming).
 *
 * POST /api/copilot_stream.php   (same JSON body as api/copilot.php)
 * Response: text/event-stream with events
 *   event: status   data: {"stage":"retrieval|thinking|cache_hit", ...}
 *   event: tool     data: {"tool":"get_agri_weather","state":"start|done","summary":"..."}
 *   event: partial  data: {"text":"<answer so far, banned names masked, invalid [n] dropped>"}
 *   event: final    data: {<exactly the JSON that api/copilot.php returns>}
 *   event: error    data: {"error":"...","http":502}
 * The model is called with stream=true, so tokens reach the phone as they are generated.
 * The final event carries the fully validated answer (citations checked, pesticide guard applied).
 * POST + fetch() streaming is used (EventSource cannot POST); the PWA falls back to copilot.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/copilot_core.php';
send_common_headers('POST, OPTIONS');
require_method('POST');
rate_limit('copilot-min', (int)cfg('RATE_LIMIT_PER_MIN'), 60);
$b = read_json_body(32 * 1024);

// ---- switch to an unbuffered SSE response
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) @ob_end_flush();
@set_time_limit(150);
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');   // nginx / LiteSpeed: do not buffer
header('Connection: keep-alive');

$last_partial = 0.0;
$send = function (string $event, array $data) use (&$last_partial): void {
    if ($event === 'partial') {           // throttle partial frames to ~12/s
        $now = microtime(true);
        if ($now - $last_partial < 0.08) return;
        $last_partial = $now;
    }
    echo "event: $event\n", 'data: ', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n\n";
    @flush();
};
echo ': ' . str_repeat(' ', 2048) . "\n\n"; // padding defeats small proxy buffers
@flush();
try {
    $out = copilot_run($b, $send);
    $send('final', $out);
} catch (CopilotError $e) {
    $send('error', ['error' => $e->getMessage(), 'http' => $e->http] + (cfg('DEBUG') ? ['details' => $e->details] : []));
} catch (Throwable $e) {
    $send('error', ['error' => 'উত্তর তৈরি করা যায়নি, আবার জিজ্ঞেস করুন।', 'http' => 500]);
}
