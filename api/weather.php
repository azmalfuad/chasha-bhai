<?php
/**
 * চাষা ভাই — Real weather + agri risk outlook
 *
 * GET /api/weather.php?location=Rajshahi           (one of the 8 divisions)
 * GET /api/weather.php?lat=24.37&lon=88.60[&name=..] (any point in Bangladesh)
 * Optional: &crop=rice|potato|...   &advisory=0 (skip AI text)
 *
 * Data   : Open-Meteo forecast API (free, no key) — real numbers only.
 * Risks  : deterministic rules in lib/agrirules.php (thresholds returned with evidence).
 * AI     : Grok only *explains* the computed numbers in Bangla (never invents them).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/agrirules.php';
require_once __DIR__ . '/lib/places.php';
require_once __DIR__ . '/lib/weather_core.php';
send_common_headers('GET, OPTIONS');
require_method('GET');
rate_limit('weather', (int)cfg('RATE_LIMIT_PER_MIN') * 2, 60);

$res = resolve_location($_GET);
$crop = clean_text(preg_replace('/[^\p{L}\s\-]/u', '', (string)($_GET['crop'] ?? '')) ?? '', 30);
$want_ai = ($_GET['advisory'] ?? '1') !== '0';

$out = agri_weather($res['lat'], $res['lon'], $res['name'], $res['name_bn'], $crop, $want_ai);
json_out($out);

