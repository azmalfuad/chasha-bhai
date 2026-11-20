<?php
/**
 * চাষা ভাই — open data exports (read-only).
 *
 * GET /api/data_export.php?dataset=outbreak&format=geojson|csv|json[&days=21]
 *     Public: only k-anonymous published cells (≥3 reports), as a GeoJSON FeatureCollection
 *     of Polygon grid cells (0.1°), or CSV / JSON rows.
 * GET /api/data_export.php?dataset=feedback_stats&format=json|csv
 *     Public: aggregate farmer-feedback counts per crop.
 * GET /api/data_export.php?dataset=labelled&format=csv|ndjson|json&token=EXPORT_TOKEN
 *     Restricted: anonymous diagnosis rows joined with farmer verdicts (no location, no photo, no IP),
 *     for building a labelled evaluation set. Disabled unless EXPORT_TOKEN is configured.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/outbreak_store.php';
require_once __DIR__ . '/lib/store.php';
send_common_headers('GET, OPTIONS');
require_method('GET');
rate_limit('export', 20, 60);

$dataset = (string)($_GET['dataset'] ?? 'outbreak');
$format = strtolower((string)($_GET['format'] ?? 'json'));
$days = min(365, max(7, (int)($_GET['days'] ?? 21)));

function export_csv(string $name, array $header, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Bangla correctly
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $r) fputcsv($out, array_map(fn($h) => is_scalar($r[$h] ?? null) || ($r[$h] ?? null) === null ? ($r[$h] ?? '') : json_encode($r[$h], JSON_UNESCAPED_UNICODE), $header), ',', '"', '');
    fclose($out); exit;
}

switch ($dataset) {
    case 'outbreak':
        $agg = outbreak_aggregate(min(60, $days));
        $half = OUTBREAK_CELL_DEG / 2;
        if ($format === 'geojson') {
            header('Content-Type: application/geo+json; charset=utf-8');
            $features = array_map(fn($c) => ['type' => 'Feature',
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                    [round($c['lon'] - $half, 3), round($c['lat'] - $half, 3)], [round($c['lon'] + $half, 3), round($c['lat'] - $half, 3)],
                    [round($c['lon'] + $half, 3), round($c['lat'] + $half, 3)], [round($c['lon'] - $half, 3), round($c['lat'] + $half, 3)],
                    [round($c['lon'] - $half, 3), round($c['lat'] - $half, 3)]]]],
                'properties' => ['cell_centre' => [$c['lon'], $c['lat']], 'reports' => $c['n'], 'reports_last7d' => $c['last7'], 'alert' => $c['alert'], 'top_diseases' => $c['top']]], $agg['cells']);
            echo json_encode(['type' => 'FeatureCollection', 'name' => 'chasha_bhai_outbreak_cells', 'features' => $features,
                'metadata' => ['window_days' => $agg['window_days'], 'k_anonymity' => OUTBREAK_K_ANON, 'cell_size_deg' => OUTBREAK_CELL_DEG,
                    'suppressed_cells' => $agg['suppressed_cells'], 'generated_at' => date('c'), 'licence' => 'CC BY 4.0 — Chasha Bhai community reports']],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $rows = array_map(fn($c) => ['cell_lat' => $c['lat'], 'cell_lon' => $c['lon'], 'reports' => $c['n'], 'reports_last7d' => $c['last7'], 'alert' => $c['alert'],
            'top_disease' => $c['top'][0]['disease'] ?? ''], $agg['cells']);
        if ($format === 'csv') export_csv('chasha_outbreak_cells.csv', ['cell_lat', 'cell_lon', 'reports', 'reports_last7d', 'alert', 'top_disease'], $rows);
        json_out(['dataset' => 'outbreak', 'rows' => $rows, 'privacy' => $agg['privacy'], 'generated_at' => date('c')]);

    case 'feedback_stats':
        $s = store_stats($days);
        if ($format === 'csv') {
            $rows = []; foreach ($s['by_crop'] as $crop => $c) $rows[] = ['crop' => $crop] + $c;
            export_csv('chasha_feedback_stats.csv', ['crop', 'diagnoses', 'correct', 'wrong'], $rows);
        }
        json_out($s + ['generated_at' => date('c')]);

    case 'labelled':
        $tok = (string)cfg('EXPORT_TOKEN');
        if ($tok === '' || !hash_equals($tok, (string)($_GET['token'] ?? ''))) json_error(403, 'Labelled export is disabled or the token is wrong.');
        $rows = store_labelled_rows($days, !empty($_GET['only_labelled']));
        $header = ['id', 'created_at', 'crop', 'disease', 'category', 'photo_conf', 'final_conf', 'agreement', 'escalated', 'weather_used', 'satellite_used', 'verdict', 'correct_label'];
        if ($format === 'csv') export_csv('chasha_labelled_diagnoses.csv', $header, $rows);
        if ($format === 'ndjson') {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            foreach ($rows as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
            exit;
        }
        json_out(['dataset' => 'labelled', 'rows' => $rows, 'count' => count($rows)]);

    default:
        json_error(400, 'dataset must be outbreak, feedback_stats or labelled');
}
