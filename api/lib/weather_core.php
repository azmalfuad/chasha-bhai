<?php
/** চাষা ভাই — weather core: location resolving, Open-Meteo fetch, rule engine call, AI advisory. */
declare(strict_types=1);
require_once __DIR__ . '/agrirules.php';
require_once __DIR__ . '/places.php';
require_once __DIR__ . '/safety.php';

// ---------------------------------------------------------------------------
function resolve_location(array $q): array {
    if (isset($q['lat'], $q['lon']) && is_numeric($q['lat']) && is_numeric($q['lon'])) {
        $lat = round((float)$q['lat'], 3); $lon = round((float)$q['lon'], 3); // ~100 m; enough for weather
        if (!in_bangladesh($lat, $lon)) json_error(400, 'অবস্থানটি বাংলাদেশের ভেতরে নয়। (Location must be inside Bangladesh)');
        $name = clean_text(preg_replace('/[^\p{L}\p{N}\s\-,.]/u', '', (string)($q['name'] ?? '')) ?? '', 60);
        $div = nearest_division($lat, $lon);
        return ['lat' => $lat, 'lon' => $lon, 'name' => $name ?: "Near $div", 'name_bn' => $name ?: (BD_DIVISIONS[$div]['bn'] . ' এর কাছাকাছি')];
    }
    $loc = trim((string)($q['location'] ?? 'Dhaka'));
    foreach (BD_DIVISIONS as $k => $v) {
        if (strcasecmp($k, $loc) === 0 || $v['bn'] === $loc || ($k === 'Chattogram' && strcasecmp($loc, 'Chittagong') === 0) || ($k === 'Barishal' && strcasecmp($loc, 'Barisal') === 0)) {
            return ['lat' => $v['lat'], 'lon' => $v['lon'], 'name' => $k, 'name_bn' => $v['bn']];
        }
    }
    json_error(400, 'Unknown location. Use one of the 8 divisions, or lat & lon (use /api/geocode.php?q= to search).');
}

function agri_weather(float $lat, float $lon, string $name, string $name_bn, string $crop = '', bool $want_ai = true): array {
    $key = sprintf('%.2f,%.2f', $lat, $lon);
    $om = cache_get('openmeteo', $key, 1800);
    $from_cache = $om !== null;
    if (!$om) {
        $params = http_build_query([
            'latitude' => $lat, 'longitude' => $lon, 'timezone' => 'Asia/Dhaka', 'past_days' => 3, 'forecast_days' => 7,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,is_day',
            'hourly'  => 'temperature_2m,relative_humidity_2m,precipitation,precipitation_probability,wind_speed_10m,wind_gusts_10m,cape,weather_code,visibility',
            'daily'   => 'temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_gusts_10m_max,uv_index_max,et0_fao_evapotranspiration,weather_code',
        ]);
        $om = http_get_json("https://api.open-meteo.com/v1/forecast?$params", 20);
        if (!$om || empty($om['hourly'])) json_error(502, 'আবহাওয়ার তথ্য আনা যায়নি (Open-Meteo unavailable). পরে চেষ্টা করুন।');
        $om['_fetched_at'] = date('c');
        cache_put('openmeteo', $key, $om);
    }
    $a = agri_analyse($om);
    $out = [
        'app' => "চাষা ভাই | Farmer's Companion",
        'location' => "$name_bn, বাংলাদেশ", 'location_en' => "$name, Bangladesh",
        'coords' => ['lat' => $lat, 'lon' => $lon],
        'date' => date('d F Y'),
        'data_source' => [
            'provider' => 'Open-Meteo.com forecast API (ECMWF/GFS/ICON best-match models)', 'url' => 'https://open-meteo.com/',
            'license' => 'CC BY 4.0', 'fetched_at' => $om['_fetched_at'] ?? null, 'model_time' => $om['current']['time'] ?? null,
            'cache' => $from_cache ? 'hit (≤30 min old)' : 'fresh',
        ],
        'method' => 'Risk levels are computed by deterministic rules from the forecast numbers (see each risk "rule" and "evidence"). AI only writes the explanation.',
    ] + $a;

    $out['advisory'] = $want_ai ? ai_advisory($out, $crop) : null;
    if (!$out['advisory']) $out['advisory'] = rule_advisory($out);
    $out['note'] = $out['advisory']['headline_bn'] ?? '';
    return $out;
}

/** Deterministic fallback advisory (used if AI is off/unavailable). */
function rule_advisory(array $w): array {
    $tips = [];
    foreach ($w['risk_outlook'] as $r) {
        if ($r['risk_level'] === 'LOW') continue;
        $tips[] = match ($r['id']) {
            'thunderstorm' => 'বজ্রঝড়ের সময় খোলা মাঠে কাজ করবেন না; পাকা ধান/ফসল দ্রুত কেটে নিরাপদে রাখুন।',
            'heavy_rain' => 'জমির নালা পরিষ্কার রাখুন যাতে পানি জমে না থাকে; বৃষ্টির আগে সার ও কীটনাশক দেবেন না।',
            'heatwave' => 'সকাল বা বিকেলে সেচ দিন; বীজতলা ও সবজিতে আংশিক ছায়া দিন।',
            'cold_fog' => 'বীজতলা রাতে পলিথিন দিয়ে ঢেকে দিন, সকালে খুলে দিন; আলু-টমেটোতে নাবি ধসা রোগের দিকে নজর রাখুন।',
            'disease_weather' => 'আর্দ্র আবহাওয়ায় ছত্রাকজনিত রোগ বাড়ে — জমি নিয়মিত পরিদর্শন করুন, আক্রান্ত পাতা সরিয়ে ফেলুন।',
            default => '',
        };
    }
    if (!$tips) $tips[] = 'আবহাওয়া স্বাভাবিক — নিয়মিত জমি পরিদর্শন চালিয়ে যান।';
    if ($w['spray_windows']) $tips[] = 'স্প্রে করার উপযুক্ত সময়: ' . bn_digits(substr($w['spray_windows'][0]['start'], 11, 5)) . ' থেকে ' . bn_digits(substr($w['spray_windows'][0]['end'], 11, 5)) . ' (' . bn_digits(substr($w['spray_windows'][0]['start'], 5, 5)) . ')।';
    return ['headline_bn' => $tips[0], 'advisory_bn' => $tips, 'generated_by' => 'rules (no AI)', 'numbers_cited' => []];
}

/** Grok writes a short Bangla explanation of the already-computed numbers. */
function ai_advisory(array $w, string $crop): ?array {
    if (!grok_available()) return null;
    $facts = [
        'location' => $w['location_en'], 'current' => $w['current_weather'],
        'risks' => array_map(fn($r) => ['id' => $r['id'], 'level' => $r['risk_level'], 'evidence' => $r['evidence']], $w['risk_outlook']),
        'spray_windows' => $w['spray_windows'], 'irrigation' => $w['irrigation'], 'month' => date('F'), 'crop' => $crop ?: null,
    ];
    $ckey = json_encode($facts);
    if ($hit = cache_get('advisory', $ckey, 3 * 3600)) return $hit + ['cache' => 'hit'];
    // STATIC system prompt first (identical bytes on every call) => xAI prompt caching kicks in.
    $system = <<<'P'
You are the agro-weather explainer of "চাষা ভাই", a Bangladeshi farmer assistant.
You receive FACTS computed from a real Open-Meteo forecast by fixed rules. Your only job is to explain them to a farmer in simple Bangla (Bangladesh standard, short sentences, no jargon).
Hard rules:
- Use ONLY numbers present in FACTS. Never invent temperatures, rainfall, dates or risk levels. Never change a risk level.
- Every advice line must be grounded in a fact; put the exact figures you used in "numbers_cited".
- Give practical field actions relevant to the current month in Bangladesh (Rabi season Nov–Feb, Kharif-1 Mar–Jun, Kharif-2 Jul–Oct). If a crop is given, tailor to it.
- Pesticide advice: never name a specific chemical product; say "কৃষি অফিসারের পরামর্শ অনুযায়ী অনুমোদিত বালাইনাশক" and prefer IPM first. Mention the spray window if one exists.
- 3 to 5 advice lines, each under 25 words. Write Bangla digits.
P;
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['headline_bn', 'advisory_bn', 'numbers_cited'],
        'properties' => ['headline_bn' => ['type' => 'string'], 'advisory_bn' => ['type' => 'array', 'items' => ['type' => 'string']], 'numbers_cited' => ['type' => 'array', 'items' => ['type' => 'string']]]];
    if (!ai_global_budget_ok()) return null; // budget exhausted -> rule-based advisory
    $r = grok_call(['model' => cfg('GROK_FAST_MODEL'), 'temperature' => 0.2, 'max_tokens' => 700, 'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => 'FACTS: ' . json_encode($facts, JSON_UNESCAPED_UNICODE)],
    ]], $schema, 'agri_advisory');
    if (!$r['ok']) return null;
    $j = extract_json($r['content']);
    if (!$j || empty($j['advisory_bn'])) return null;
    $j['generated_by'] = 'চাষা ভাই AI explanation of rule-computed facts';
    $j['grounding'] = grounding_check(array_merge([$j['headline_bn']], $j['advisory_bn']), $facts);
    $f = []; $j = pesticide_guard($j, $f); if ($f) $j['pesticide_flags'] = $f;
    $j['tokens'] = ['in' => $r['usage']['prompt_tokens'] ?? null, 'cached' => $r['usage']['prompt_tokens_details']['cached_tokens'] ?? null, 'out' => $r['usage']['completion_tokens'] ?? null];
    cache_put('advisory', $ckey, $j);
    return $j;
}
