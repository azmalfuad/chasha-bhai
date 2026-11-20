<?php
/**
 * চাষা ভাই — "তিন-চোখ" (Three-Eye) diagnosis API
 *
 * POST /api/disease.php   JSON:
 *   {
 *     "image": "data:image/jpeg;base64,...",       (required; client downsizes to ≤1280 px)
 *     "lat": 24.37, "lon": 88.60,                   (optional — enables weather fusion)
 *     "crop_hint": "ধান",                           (optional)
 *     "field": {"level":"HIGH","attribution":"field_specific","latest_ndvi":0.41},  (optional, from /api/ndvi.php)
 *     "share_anonymous": true                       (optional opt-in community outbreak report)
 *   }
 *
 * Eye 1  Photo  : Grok Vision, strict JSON schema, calibrated confidence + differential.
 * Eye 2  Weather: real Open-Meteo data -> does the weather favour this disease?
 * Eye 3  Space  : Sentinel-2 NDVI trend of the farmer's field (field-specific vs regional).
 * The fusion is a transparent formula (see "fusion.formula") — not another black box.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/vision.php';
require_once __DIR__ . '/lib/weather_core.php';
require_once __DIR__ . '/lib/kb.php';
require_once __DIR__ . '/lib/safety.php';
require_once __DIR__ . '/lib/outbreak_store.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/webhook.php';
send_common_headers('POST, OPTIONS');
require_method('POST');
rate_limit('disease-min', (int)cfg('RATE_LIMIT_PER_MIN'), 60);
rate_limit('ai-day', (int)cfg('AI_RATE_LIMIT_PER_DAY'), 86400);

$max = (int)cfg('MAX_IMAGE_BYTES');
$body = read_json_body((int)($max * 1.4) + 4096);
if (empty($body['image']) || !is_string($body['image'])) json_error(400, 'কোনো ছবি পাওয়া যায়নি। (No image provided)');
[$mime, $bin, $w, $h] = validate_image_data_url($body['image'], $max);
if (!grok_available()) json_error(503, 'এআই সেবা এখন বন্ধ (AI key not configured on server).');

$crop_hint = clean_text(preg_replace('/[^\p{L}\p{M}\s\-]/u', '', (string)($body['crop_hint'] ?? '')) ?? '', 30);
$lat = isset($body['lat']) && is_numeric($body['lat']) ? (float)$body['lat'] : null;
$lon = isset($body['lon']) && is_numeric($body['lon']) ? (float)$body['lon'] : null;
if ($lat !== null && ($lon === null || !in_bangladesh($lat, $lon))) { $lat = $lon = null; }

// ---------- Eye 1: photo
$t0 = microtime(true);
$v = diagnose_image("data:$mime;base64," . base64_encode($bin), $crop_hint);
unset($bin);
if (!$v['ok']) json_error(502, 'এআই বিশ্লেষণ ব্যর্থ হয়েছে, আবার চেষ্টা করুন। (Vision model error)', $v['error'] ?? null);
$d = $v['data'];
$dd = &$d['disease_detection'];
$conf = max(0.0, min(1.0, (float)($dd['confidence'] ?? 0)));
$usable = (bool)($d['image_quality']['usable'] ?? false);
$category = (string)($dd['category'] ?? 'unknown');
$is_claim = $usable && !in_array($category, ['healthy', 'unknown'], true);

// ---------- Eye 2: weather (real data, no AI text needed here)
$weather = null; $wsup = ['type' => 'n/a', 'support' => 0.0, 'why_en' => 'No location shared — weather check skipped.'];
if ($lat !== null) {
    try {
        $wx = agri_weather(round($lat, 2), round($lon, 2), 'Field', 'আপনার জমি', '', false);
        $wsup = disease_weather_support((string)$dd['disease'] . ' ' . $category, $wx['risk_outlook']);
        $weather = ['current' => $wx['current_weather'], 'disease_weather' => array_values(array_filter($wx['risk_outlook'], fn($r) => $r['id'] === 'disease_weather'))[0] ?? null,
            'spray_windows' => $wx['spray_windows'], 'source' => $wx['data_source']];
    } catch (Throwable $e) { $wsup['why_en'] = 'Weather unavailable.'; }
}

// ---------- Eye 3: satellite (summary supplied by client from /api/ndvi.php)
$field = is_array($body['field'] ?? null) ? $body['field'] : null;
$ssup = 0.0; $s_why = 'No field drawn — satellite check skipped.';
if ($field) {
    $lvl = strtoupper((string)($field['level'] ?? '')); $attr = (string)($field['attribution'] ?? '');
    if (in_array($lvl, ['HIGH', 'MEDIUM'], true) && $attr === 'field_specific') { $ssup = 0.5; $s_why = 'Satellite shows a field-specific NDVI drop (neighbours stable) — consistent with a pest/disease problem.'; }
    elseif (in_array($lvl, ['HIGH', 'MEDIUM'], true) && $attr === 'regional') { $ssup = 0.0; $s_why = 'NDVI dropped but neighbours dropped too — likely seasonal; neither supports nor rejects the diagnosis.'; }
    elseif (in_array($lvl, ['HIGH', 'MEDIUM'], true)) { $ssup = 0.2; $s_why = 'Satellite shows an NDVI drop in the field.'; }
    elseif ($lvl === 'LOW') { $ssup = 0.0; $s_why = 'Field canopy stable on satellite — problem may still be early or patchy (10 m pixels miss small spots).'; }
}

// ---------- Fusion (transparent)
$final = $conf;
if ($is_claim) $final = max(0.0, min(1.0, $conf + 0.15 * $wsup['support'] + 0.10 * $ssup));
$agree = !$is_claim ? 'n/a' : (($wsup['support'] <= -0.2) ? 'weather_disagrees' : (($wsup['support'] >= 0.2 || $ssup >= 0.2) ? 'corroborated' : 'photo_only'));
$infection = (float)($dd['infection_percent'] ?? 0);
$escalate = !$usable || $category === 'unknown' || ($is_claim && ($final < 0.55 || $infection >= 40 || $category === 'viral'));
$band = $final >= 0.75 ? 'উচ্চ' : ($final >= 0.5 ? 'মাঝারি' : 'কম');

$why_bn = [];
$why_bn[] = 'ছবি: ' . (count($d['visual_evidence'] ?? []) ? implode('; ', array_slice($d['visual_evidence'], 0, 3)) : 'স্পষ্ট লক্ষণ পাওয়া যায়নি');
if ($lat !== null) $why_bn[] = 'আবহাওয়া: ' . ($wsup['support'] >= 0.2 ? 'সাম্প্রতিক আর্দ্রতা/বৃষ্টি এই রোগের অনুকূল' : ($wsup['support'] <= -0.2 ? 'বর্তমান আবহাওয়া এই রোগের তেমন অনুকূল নয় — অন্য কারণও খতিয়ে দেখুন' : 'আবহাওয়া নিরপেক্ষ')) . ' (' . ($weather['disease_weather']['details'] ?? '') . ')';
if ($field) $why_bn[] = 'স্যাটেলাইট: ' . ($ssup >= 0.5 ? 'শুধু আপনার জমির সবুজতা কমেছে, আশেপাশের জমি ঠিক আছে' : ($ssup > 0 ? 'জমির সবুজতা কমেছে' : 'জমির সবুজতায় বিশেষ পরিবর্তন নেই বা পুরো এলাকায় একই রকম'));

unset($dd);
// ---------- Guardrails
$flags = [];
$d = pesticide_guard($d, $flags);

// ---------- Grounding: link to curated knowledge (citations)
$refs = array_map(fn($r) => ['id' => $r['doc']['id'], 'title_bn' => $r['doc']['title_bn'], 'title_en' => $r['doc']['title_en'], 'source' => $r['doc']['source'], 'score' => $r['score']],
    kb_search(($d['kb_query'] ?? '') . ' ' . ($d['disease_detection']['disease'] ?? '') . ' ' . ($d['disease_detection']['disease_bn'] ?? '') . ' ' . ($d['crop'] ?? ''), 2, 2.0));

// ---------- Opt-in community surveillance
$report_id = null;
if (!empty($body['share_anonymous']) && $lat !== null && $is_claim && $final >= 0.5) {
    $report_id = outbreak_add($lat, $lon, (string)($d['crop'] ?? ''), (string)$d['disease_detection']['disease'], $category);
    try { webhook_outbreak_check($lat, $lon); } catch (Throwable $e) { /* best-effort */ }
}

$d['app_name'] = 'চাষা ভাই'; $d['app_name_en'] = "Farmer's Companion";
$d['last_updated'] = date('Y-m-d');
$d['fusion'] = [
    'name' => 'তিন-চোখ যাচাই (Three-Eye check)',
    'photo_confidence' => round($conf, 2),
    'weather' => ['support' => $wsup['support'], 'disease_type' => $wsup['type'], 'why_en' => $wsup['why_en'], 'used' => $lat !== null],
    'satellite' => ['support' => $ssup, 'why_en' => $s_why, 'used' => (bool)$field],
    'final_confidence' => round($final, 2), 'confidence_band_bn' => $band, 'agreement' => $agree,
    'formula' => 'final = clamp(photo + 0.15 × weather_support + 0.10 × satellite_support, 0, 1); supports are in [-1, 1]',
    'why_bn' => $why_bn,
    'escalate' => $escalate,
    'escalate_bn' => $escalate ? 'নিশ্চিত হতে কৃষি কল সেন্টার ১৬১২৩-এ ফোন করুন বা উপজেলা কৃষি অফিসে আক্রান্ত গাছের নমুনা নিয়ে যান।' : null,
];
$d['weather_context'] = $weather;
$d['references'] = $refs;
$d['safety'] = safety_block() + ['pesticide_flags' => $flags];
$d['community_report'] = ['shared' => (bool)$report_id, 'report_id' => $report_id];
$d['meta'] = ['model' => 'চাষা ভাই AI', 'latency_ms' => (int)((microtime(true) - $t0) * 1000), 'image_px' => "{$w}x{$h}",
    'tokens' => ['in' => $v['usage']['prompt_tokens'] ?? null, 'image' => $v['usage']['prompt_tokens_details']['image_tokens'] ?? null, 'cached' => $v['usage']['prompt_tokens_details']['cached_tokens'] ?? null, 'out' => $v['usage']['completion_tokens'] ?? null],
    'disclaimer_bn' => 'এটি এআই-ভিত্তিক প্রাথমিক পরামর্শ, চূড়ান্ত রোগ নির্ণয় নয়। গুরুতর ক্ষেত্রে কৃষি কর্মকর্তার পরামর্শ নিন।'];
unset($d['kb_query']);

// ---------- Anonymous diagnosis log + farmer feedback hook (no photo, no IP, grid cell only)
$diag_id = null;
try {
    $diag_id = store_add_diagnosis(['crop' => (string)($d['crop'] ?? ''), 'disease' => (string)($d['disease_detection']['disease'] ?? ''), 'category' => $category,
        'photo_conf' => $conf, 'final_conf' => $final, 'agreement' => $agree, 'escalated' => $escalate,
        'weather_used' => $lat !== null, 'satellite_used' => (bool)$field, 'lat' => $lat, 'lon' => $lon, 'latency_ms' => $d['meta']['latency_ms']]);
} catch (Throwable $e) { $diag_id = null; }
$d['feedback'] = ['diagnosis_id' => $diag_id, 'endpoint' => 'api/feedback.php', 'verdicts' => STORE_VERDICTS];
json_out($d);
