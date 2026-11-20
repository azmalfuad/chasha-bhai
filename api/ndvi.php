<?php
/**
 * চাষা ভাই — satellite crop-health proxy -> Python microservice (services/satellite).
 * GET  /api/ndvi.php?lat=..&lon=..&radius_m=60&days=120
 * POST /api/ndvi.php  {"geometry": GeoJSON Polygon, "days": 120}
 * Keeps the browser on one origin and lets us rate-limit + cache.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
send_common_headers('GET, POST, OPTIONS');
rate_limit('ndvi', 10, 60);
$base = rtrim((string)cfg('SAT_SERVICE_URL'), '/');
if ($base === '') json_error(503, 'স্যাটেলাইট সেবা চালু নেই (SAT_SERVICE_URL not configured).');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    foreach (['lat', 'lon'] as $k) if (!isset($_GET[$k]) || !is_numeric($_GET[$k])) json_error(400, "$k is required");
    $lat = (float)$_GET['lat']; $lon = (float)$_GET['lon'];
    if (!in_bangladesh($lat, $lon)) json_error(400, 'জমিটি বাংলাদেশের ভেতরে হতে হবে।');
    $q = ['lat' => round($lat, 5), 'lon' => round($lon, 5), 'radius_m' => min(400, max(10, (float)($_GET['radius_m'] ?? 60))), 'days' => min(400, max(20, (int)($_GET['days'] ?? 120)))];
    $key = json_encode($q) . date('Ymd');
    if ($hit = cache_get('ndvi', $key, 6 * 3600)) json_out($hit + ['proxy_cache' => 'hit']);
    $ch = curl_init("$base/ndvi?" . http_build_query($q));
} elseif ($method === 'POST') {
    $b = read_json_body(64 * 1024);
    if (($b['geometry']['type'] ?? '') !== 'Polygon') json_error(400, 'geometry must be a GeoJSON Polygon');
    $payload = json_encode(['geometry' => $b['geometry'], 'days' => min(400, max(20, (int)($b['days'] ?? 120)))]);
    $key = $payload . date('Ymd');
    if ($hit = cache_get('ndvi', $key, 6 * 3600)) json_out($hit + ['proxy_cache' => 'hit']);
    $ch = curl_init("$base/ndvi");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
} else json_error(405, 'Use GET or POST');

curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 10]);
$r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$d = json_decode((string)$r, true);
if ($code !== 200 || !is_array($d)) json_error($code >= 400 && $code < 500 ? $code : 502, is_array($d) && isset($d['detail']) ? (is_string($d['detail']) ? $d['detail'] : 'Invalid field') : 'স্যাটেলাইট সেবা সাড়া দেয়নি। পরে চেষ্টা করুন (free tier may be waking up ~30–60 s).');
cache_put('ndvi', $key, $d);
json_out($d);
