<?php
/**
 * চাষা ভাই — community outbreak early-warning (opt-in, anonymised, k-anonymous)
 * GET /api/outbreak.php[?days=21]           -> published cells (≥3 reports) for the heat-map
 * GET /api/outbreak.php?lat=..&lon=..       -> also "near you" alerts within ~25 km
 * Reports are only created by /api/disease.php when the farmer ticks "share anonymously".
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/outbreak_store.php';
send_common_headers('GET, OPTIONS');
require_method('GET');
rate_limit('outbreak', (int)cfg('RATE_LIMIT_PER_MIN') * 2, 60);
$days = min(60, max(7, (int)($_GET['days'] ?? 21)));
$agg = outbreak_aggregate($days);
if (isset($_GET['lat'], $_GET['lon']) && is_numeric($_GET['lat']) && is_numeric($_GET['lon'])) {
    $lat = (float)$_GET['lat']; $lon = (float)$_GET['lon'];
    $near = [];
    foreach ($agg['cells'] as $c) {
        $km = 111.2 * sqrt(($c['lat'] - $lat) ** 2 + (($c['lon'] - $lon) * cos(deg2rad($lat))) ** 2);
        if ($km <= 25) $near[] = $c + ['distance_km' => round($km)];
    }
    $agg['near_you'] = $near;
}
$agg['generated_at'] = date('c');
json_out($agg);
