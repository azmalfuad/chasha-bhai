<?php
/**
 * চাষা ভাই — offline test suite (no AI key, no network needed).
 *   php eval/test_offline.php
 * Covers: guardrails (pesticide guard, numeric grounding), BM25 retrieval, the answer-cache
 * normaliser, streamed-partial cleaning, the SQLite store AND its JSON-lines fallback
 * (diagnosis log, feedback upsert, stats, retention), webhook HMAC signing, k-anonymity.
 * Exit code 0 = all passed. Uses a temporary DATA_DIR, never the real data/runtime.
 */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/chasha_test_' . bin2hex(random_bytes(4));
putenv("DATA_DIR=$tmp");
putenv('WEBHOOK_SECRET=test-secret');
putenv('GROK_API_KEY=');               // make sure no real key is used
$_SERVER['SCRIPT_NAME'] = 'test_offline.php';
require __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/lib/copilot_core.php';   // also loads kb, weather_core, outbreak_store, safety
require_once __DIR__ . '/../api/lib/store.php';
require_once __DIR__ . '/../api/lib/webhook.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($info ? " — $info" : '') . "\n"; }
}

echo "Guardrails\n";
$f = []; $t = pesticide_guard('Use Endosulfan now', $f);
check('POP pesticide is masked', strpos($t, 'Endosulfan') === false && $f && $f[0]['level'] === 'blocked');
$f = []; $t = pesticide_guard('carbofuran can be used', $f);
check('WHO Ia/Ib substance is flagged', $f && $f[0]['level'] === 'warning');
$g = grounding_check(['তাপমাত্রা ৩৪ ডিগ্রি, বৃষ্টি ৯৯ মিমি'], ['temp' => 34.2, 'rain' => 12]);
check('ungrounded number (99) is caught', $g['unverified'] === [99.0] && !$g['grounded'], json_encode($g));
$g = grounding_check(['তাপমাত্রা ৩৪ ডিগ্রি'], ['temp' => 34.2]);
check('grounded number passes', $g['grounded']);

echo "Retrieval (BM25 + contextual chunks)\n";
$hits = kb_search('rice blast ধানের ব্লাস্ট', 3, 1.0);
check('rice blast query returns the rice-blast doc first', ($hits[0]['doc']['id'] ?? '') === 'rice-blast', $hits[0]['doc']['id'] ?? 'none');

echo "Answer cache + streaming helpers\n";
check('normaliser ignores spacing/punctuation/digits', copilot_norm_question('ধানের   ব্লাস্ট রোগ হলে কী করব ?') === copilot_norm_question('ধানের ব্লাস্ট রোগ হলে কী করব') && copilot_norm_question('৩ দিন') === '3 দিন');
check('no cache key for follow-up turns', copilot_cache_key('q', [['role' => 'user', 'content' => 'x']], null, null, [], 'm') === null);
check('cache key differs by ~11 km cell', copilot_cache_key('q', [], 24.37, 88.6, [], 'm') !== copilot_cache_key('q', [], 23.81, 90.41, [], 'm'));
$p = copilot_partial_clean('Use endosulfan now [1] and [7] then Endo', 2);
check('partial: banned name masked, bad [7] dropped, trailing half-word held back', strpos($p, 'endosulfan') === false && strpos($p, '[1]') !== false && strpos($p, '[7]') === false && strpos($p, 'Endo') === false, $p);

foreach (['sqlite', 'json'] as $drv) {
    if ($drv === 'sqlite' && !(class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true))) { echo "Store [sqlite] skipped (pdo_sqlite missing)\n"; continue; }
    echo "Store [$drv]\n";
    putenv("STORE_DRIVER=$drv");
    // fresh config + static state per driver: run in a child process
    $code = <<<'PHPCODE'
declare(strict_types=1);
require getenv('CB_ROOT') . '/api/bootstrap.php';
require_once getenv('CB_ROOT') . '/api/lib/outbreak_store.php';
require_once getenv('CB_ROOT') . '/api/lib/store.php';
$o = [];
$o['driver'] = store_driver();
$id = store_add_diagnosis(['crop' => 'rice', 'disease' => 'Rice blast', 'category' => 'fungal', 'photo_conf' => 0.7, 'final_conf' => 0.8, 'lat' => 24.37, 'lon' => 88.6]);
$o['id_ok'] = (bool)preg_match('/^[a-f0-9]{16}$/', $id);
$row = store_get_diagnosis($id);
$o['cell_only'] = $row && (float)$row['cell_lat'] === 24.35 && !isset($row['lat']);
$o['fb1'] = store_add_feedback($id, 'wrong', 'Brown spot')['ok'];
$o['fb2'] = store_add_feedback($id, 'correct')['ok'];
$o['bad_verdict'] = store_add_feedback($id, 'maybe')['ok'];
$o['bad_id'] = store_add_feedback('ffffffffffffffff', 'correct')['ok'];
$s = store_stats(30);
$o['stats'] = [$s['diagnoses'], $s['with_feedback'], $s['verdicts']['correct'], $s['verdicts']['wrong']];
$o['wh_first'] = store_webhook_once('k1'); $o['wh_again'] = store_webhook_once('k1');
echo json_encode($o);
PHPCODE;
    $tmp2 = "$tmp/$drv"; @mkdir($tmp2, 0775, true);
    $cmd = 'CB_ROOT=' . escapeshellarg(dirname(__DIR__)) . ' DATA_DIR=' . escapeshellarg($tmp2) . ' STORE_DRIVER=' . $drv . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    $o = json_decode((string)shell_exec($cmd), true) ?: [];
    check("driver is $drv", ($o['driver'] ?? '') === $drv, json_encode($o));
    check('diagnosis id issued', !empty($o['id_ok']));
    check('only a 0.1° grid cell is stored (no exact GPS)', !empty($o['cell_only']));
    check('feedback saved and upserted', !empty($o['fb1']) && !empty($o['fb2']));
    check('invalid verdict / unknown id rejected', empty($o['bad_verdict']) && empty($o['bad_id']));
    check('stats count the latest verdict once', ($o['stats'] ?? []) === [1, 1, 1, 0], json_encode($o['stats'] ?? null));
    check('webhook dedupe', ($o['wh_first'] ?? false) === true && ($o['wh_again'] ?? true) === false);
}

echo "Webhook signing\n";
$body = '{"event":"x"}';
check('HMAC-SHA256 signature', webhook_sign($body) === 'sha256=' . hash_hmac('sha256', $body, 'test-secret'));
check('webhook disabled without WEBHOOK_URL', webhook_send('x', []) === 0);

echo "k-anonymity\n";
for ($i = 0; $i < 2; $i++) outbreak_add(23.81, 90.41, 'rice', 'Rice blast', 'fungal');
$agg = outbreak_aggregate(21);
check('cell with 2 reports is suppressed', count($agg['cells']) === 0 && $agg['suppressed_cells'] === 1);
outbreak_add(23.82, 90.42, 'rice', 'Rice blast', 'fungal');
check('cell with 3 reports is published', count(outbreak_aggregate(21)['cells']) === 1);

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $x) $x->isDir() ? @rmdir($x->getPathname()) : @unlink($x->getPathname());
@rmdir($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
