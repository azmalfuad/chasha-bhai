<?php
/** GET /api/geocode.php?q=Bogura  -> places in Bangladesh (Open-Meteo geocoding, free, no key) */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
send_common_headers('GET, OPTIONS');
require_method('GET');
rate_limit('geocode', (int)cfg('RATE_LIMIT_PER_MIN') * 2, 60);
$q = clean_text(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', (string)($_GET['q'] ?? '')) ?? '', 60);
if (mb_strlen($q) < 2) json_error(400, 'q must be at least 2 characters');
$hit = cache_get('geocode', mb_strtolower($q), 86400 * 7);
if ($hit === null) {
    $d = http_get_json('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name' => $q, 'count' => 8, 'language' => 'en', 'countryCode' => 'BD']));
    if ($d === null) json_error(502, 'Geocoding service unavailable');
    $hit = array_map(fn($r) => ['name' => $r['name'], 'district' => $r['admin2'] ?? null, 'division' => $r['admin1'] ?? null,
        'lat' => $r['latitude'], 'lon' => $r['longitude']], $d['results'] ?? []);
    cache_put('geocode', mb_strtolower($q), $hit);
}
json_out(['query' => $q, 'results' => $hit, 'source' => 'Open-Meteo Geocoding API (GeoNames data, CC BY 4.0)']);
