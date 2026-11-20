<?php
/**
 * চাষা ভাই — farmer feedback / labelling loop.
 *
 * POST /api/feedback.php  {"diagnosis_id":"<16 hex>", "verdict":"correct|wrong|unsure", "correct_label":"(optional, ≤80 chars)"}
 *      -> {"ok":true}
 * GET  /api/feedback.php?stats=1[&days=90]
 *      -> aggregate counts only (diagnoses, verdicts, self-reported agreement rate, per crop)
 *
 * The diagnosis_id comes from /api/disease.php ("feedback.diagnosis_id"). Nothing personal is stored:
 * no photo, no IP, no name/phone, and only a 0.1° grid cell for location (see api/lib/store.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/outbreak_store.php';
require_once __DIR__ . '/lib/store.php';
send_common_headers('GET, POST, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    rate_limit('feedback-get', 30, 60);
    $days = min(365, max(7, (int)($_GET['days'] ?? 90)));
    json_out(store_stats($days) + ['generated_at' => date('c')]);
}
require_method('POST');
rate_limit('feedback-post', 10, 60);
$b = read_json_body(4096);
$id = (string)($b['diagnosis_id'] ?? '');
$verdict = strtolower((string)($b['verdict'] ?? ''));
$label = clean_text(preg_replace('/[^\p{L}\p{M}\p{N}\s\-\(\),.\/]/u', '', (string)($b['correct_label'] ?? '')) ?? '', 80);
if (!preg_match('/^[a-f0-9]{16}$/', $id)) json_error(400, 'diagnosis_id is missing or invalid.');
try {
    $r = store_add_feedback($id, $verdict, $label);
} catch (Throwable $e) {
    json_error(500, 'মতামত সংরক্ষণ করা যায়নি। (Could not save feedback)', $e->getMessage());
}
if (!$r['ok']) json_error($r['error'] === 'unknown diagnosis_id' ? 404 : 400, $r['error']);
json_out(['ok' => true, 'diagnosis_id' => $id, 'verdict' => $verdict, 'thanks_bn' => 'ধন্যবাদ! আপনার মতামত সংরক্ষণ হয়েছে।']);
