<?php
/**
 * চাষা ভাই — privacy-preserving community outbreak store.
 * Stores ONLY: coarse grid cell (0.1° ≈ 11 km), date, crop, disease label, category.
 * Never stores images, IPs, names, phone numbers or exact coordinates.
 * Cells are only published when they reach K_ANON distinct reports (k-anonymity).
 */
declare(strict_types=1);
const OUTBREAK_CELL_DEG = 0.1;
const OUTBREAK_K_ANON = 3;

function outbreak_cell(float $lat, float $lon): array {
    $c = OUTBREAK_CELL_DEG;
    return [round(floor($lat / $c) * $c + $c / 2, 2), round(floor($lon / $c) * $c + $c / 2, 2)];
}

function outbreak_add(float $lat, float $lon, string $crop, string $disease, string $category): string {
    [$clat, $clon] = outbreak_cell($lat, $lon);
    $rec = ['d' => date('Y-m-d'), 'lat' => $clat, 'lon' => $clon, 'crop' => mb_substr($crop, 0, 30), 'disease' => mb_substr($disease, 0, 60), 'cat' => $category];
    $id = substr(hash('sha256', json_encode($rec) . random_bytes(8)), 0, 12);
    $rec['id'] = $id;
    @file_put_contents(cb_data_dir('outbreak') . '/reports-' . date('Y-m') . '.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    return $id;
}

function outbreak_read(int $days): array {
    $since = date('Y-m-d', strtotime("-$days days"));
    $rows = [];
    foreach (glob(cb_data_dir('outbreak') . '/reports-*.jsonl') ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) { $r = json_decode($l, true); if ($r && $r['d'] >= $since) $rows[] = $r; }
    }
    return $rows;
}

/** Aggregate to cells; suppress cells with < k reports; compute 7-day vs prior trend. */
function outbreak_aggregate(int $days = 21): array {
    $rows = outbreak_read($days); $cells = [];
    $recent = date('Y-m-d', strtotime('-7 days'));
    foreach ($rows as $r) {
        $k = $r['lat'] . ',' . $r['lon'];
        $cells[$k] ??= ['lat' => $r['lat'], 'lon' => $r['lon'], 'n' => 0, 'last7' => 0, 'diseases' => []];
        $cells[$k]['n']++;
        if ($r['d'] >= $recent) $cells[$k]['last7']++;
        $cells[$k]['diseases'][$r['disease']] = ($cells[$k]['diseases'][$r['disease']] ?? 0) + 1;
    }
    $pub = []; $suppressed = 0;
    foreach ($cells as $c) {
        if ($c['n'] < OUTBREAK_K_ANON) { $suppressed++; continue; }
        arsort($c['diseases']);
        $c['top'] = array_slice(array_map(fn($d, $n) => ['disease' => $d, 'n' => $n], array_keys($c['diseases']), $c['diseases']), 0, 3);
        unset($c['diseases']);
        $prior = $c['n'] - $c['last7'];
        $c['alert'] = $c['last7'] >= 5 && $c['last7'] >= 2 * max(1, $prior) ? 'RISING' : ($c['last7'] >= 3 ? 'ACTIVE' : 'WATCH');
        $pub[] = $c;
    }
    usort($pub, fn($a, $b) => $b['last7'] <=> $a['last7']);
    return ['cells' => $pub, 'suppressed_cells' => $suppressed, 'total_reports' => count($rows), 'window_days' => $days,
        'privacy' => ['cell_size_deg' => OUTBREAK_CELL_DEG, 'k_anonymity' => OUTBREAK_K_ANON, 'stored_fields' => ['date', 'grid cell centre', 'crop', 'disease label', 'category']]];
}
