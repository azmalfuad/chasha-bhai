<?php
/**
 * চাষা ভাই — Krishi Copilot: agentic Bangla Q&A with RAG + tools + citations.
 *
 * POST /api/copilot.php  {"question": "...", "history": [{"role":"user|assistant","content":"..."}],
 *                         "lat": 24.3, "lon": 88.6, "context": {"last_diagnosis": "...", "crop": "..."}}
 *
 * Loop (max 3 tool rounds): the LLM may call
 *   search_krishi_kb(query)            -> curated BRRI/IRRI/FAO/BMD/AIS knowledge (BM25)
 *   get_agri_weather(lat, lon)         -> real Open-Meteo numbers + rule-based risks
 *   get_outbreak_alerts(lat, lon)      -> anonymised community reports near the farmer
 * Final answer cites sources as [1], [2] … mapped to the retrieved documents.
 * Model routing: simple questions -> fast model; complex -> reasoning model.
 * The logic lives in api/lib/copilot_core.php (shared with the SSE endpoint api/copilot_stream.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/copilot_core.php';
send_common_headers('POST, OPTIONS');
require_method('POST');
rate_limit('copilot-min', (int)cfg('RATE_LIMIT_PER_MIN'), 60);

$b = read_json_body(32 * 1024);
try {
    json_out(copilot_run($b, null));
} catch (CopilotError $e) {
    json_error($e->http, $e->getMessage(), $e->details);
}
