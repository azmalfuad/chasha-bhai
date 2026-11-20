<?php
/**
 * চাষা ভাই — CSV export of a field's satellite greenness history.
 *
 * GET  /api/ndvi_export.php?lat=..&lon=..&radius_m=50&days=120
 * POST /api/ndvi_export.php  {"geometry": GeoJSON Polygon, "days": 120}
 *
 * Reuses the same SAT_SERVICE_URL + file cache as ndvi.php. One row per
 * Sentinel-2 scene: date, usable flag, NDVI, neighbour NDVI, NDWI, NDMI.
 * The PNG thumbnail is omitted (it is not tabular). No API keys in the file.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
send_common_headers('GET, POST, OPTIONS');
rate_limit('ndvi-export', 10, 60);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$qmeta = [];
if ($method === 'GET') {
    foreach (['lat', 'lon'] as $k) if (!isset($_GET[$k]) || !is_numeric($_GET[$k])) json_error(400, "$k is required");
    $lat = (float)$_GET['lat']; $lon = (float)$_GET['lon'];
    if (!in_bangladesh($lat, $lon)) json_error(400, 'জমিটি বাংলাদেশের ভেতরে হতে হবে।');
    $base = rtrim((string)cfg('SAT_SERVICE_URL'), '/');
    if ($base === '') json_error(503, 'স্যাটেলাইট সেবা চালু নেই (SAT_SERVICE_URL not configured).');
    $q = ['lat' => round($lat, 5), 'lon' => round($lon, 5), 'radius_m' => min(400, max(10, (float)($_GET['radius_m'] ?? 60))), 'days' => min(400, max(20, (int)($_GET['days'] ?? 120)))];
    $qmeta = $q;
    $key = json_encode($q) . date('Ymd');
    $d = cache_get('ndvi', $key, 6 * 3600);
    if (!$d) {
        $ch = curl_init("$base/ndvi?" . http_build_query($q));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 10]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode((string)$r, true);
        if ($code !== 200 || !is_array($d)) json_error($code >= 400 && $code < 500 ? $code : 502, is_array($d) && isset($d['detail']) ? (is_string($d['detail']) ? $d['detail'] : 'Invalid field') : 'স্যাটেলাইট সেবা সাড়া দেয়নি। পরে চেষ্টা করুন।');
        cache_put('ndvi', $key, $d);
    }
} elseif ($method === 'POST') {
    $base = rtrim((string)cfg('SAT_SERVICE_URL'), '/');
    if ($base === '') json_error(503, 'স্যাটেলাইট সেবা চালু নেই (SAT_SERVICE_URL not configured).');
    $b = read_json_body(64 * 1024);
    if (($b['geometry']['type'] ?? '') !== 'Polygon') json_error(400, 'geometry must be a GeoJSON Polygon');
    $payload = json_encode(['geometry' => $b['geometry'], 'days' => min(400, max(20, (int)($b['days'] ?? 120)))]);
    $qmeta = ['geometry' => 'polygon', 'days' => min(400, max(20, (int)($b['days'] ?? 120)))];
    $key = $payload . date('Ymd');
    $d = cache_get('ndvi', $key, 6 * 3600);
    if (!$d) {
        $ch = curl_init("$base/ndvi");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $d = json_decode((string)$r, true);
        if ($code !== 200 || !is_array($d)) json_error($code >= 400 && $code < 500 ? $code : 502, is_array($d) && isset($d['detail']) ? (is_string($d['detail']) ? $d['detail'] : 'Invalid field') : 'স্যাটেলাইট সেবা সাড়া দেয়নি। পরে চেষ্টা করুন।');
        cache_put('ndvi', $key, $d);
    }
} else json_error(405, 'Use GET or POST');

$it = is_array($d['interpretation'] ?? null) ? $d['interpretation'] : [];
$series = is_array($d['series'] ?? null) ? $d['series'] : [];

$fh = fopen('php://temp', 'w+');
fputcsv($fh, [
    'date', 'usable', 'ndvi', 'neighbour_ndvi', 'ndwi', 'ndmi',
    'reason', 'cloud_cover', 'field_area_ha', 'period_days',
    'latest_level', 'attribution', 'baseline_ndvi', 'source', 'license',
], ',', '"', '\\');
foreach ($series as $row) {
    if (!is_array($row)) continue;
    fputcsv($fh, [
        (string)($row['date'] ?? ''),
        !empty($row['usable']) ? '1' : '0',
        $row['ndvi'] ?? '',
        $row['neighbour_ndvi'] ?? '',
        $row['ndwi'] ?? '',
        $row['ndmi'] ?? '',
        (string)($row['reason'] ?? ''),
        $row['cloud_cover'] ?? ($row['eo:cloud_cover'] ?? ''),
        $d['field_area_ha'] ?? '',
        $d['period_days'] ?? '',
        (string)($it['level'] ?? ''),
        (string)($it['attribution'] ?? ''),
        $it['baseline_ndvi'] ?? '',
        (string)($d['source'] ?? ''),
        (string)($d['license'] ?? ''),
    ], ',', '"', '\\');
}
rewind($fh);
$csv = stream_get_contents($fh);
fclose($fh);

$stamp = date('Ymd');
$latlon = isset($qmeta['lat']) ? (string)$qmeta['lat'] . '_' . (string)$qmeta['lon'] : 'field';
$filename = 'chasha-bhai-greenness-' . $latlon . '-' . $stamp . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo $csv;
exit;
