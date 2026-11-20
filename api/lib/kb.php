<?php
/**
 * চাষা ভাই — bilingual BM25 retriever over data/kb/krishi_kb.json.
 * No vector DB needed for ~20–200 curated docs; runs on any shared PHP host.
 *
 * Retrieval (v2):
 *  - Variable-size chunks: each document is split into sentence packs of
 *    different lengths (short last packs are kept short; packs also break
 *    when a management/treatment sentence starts a new topic).
 *  - Contextual RAG: every chunk is indexed and returned with a header
 *    naming the parent title, crop, type, part number and source, so a
 *    mid-document pack still carries document context (Anthropic-style).
 *  - Fields are weighted: keywords x3, title x2, contextual header x2, body x1.
 *  - Light Bangla suffix stripping.
 */
declare(strict_types=1);

function kb_docs(): array {
    static $docs = null;
    if ($docs === null) {
        $j = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/data/kb/krishi_kb.json'), true);
        $docs = $j['docs'] ?? [];
    }
    return $docs;
}

function kb_tokens(string $s): array {
    $s = mb_strtolower($s);
    preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $s, $m);
    $out = [];
    static $stop = ['the','a','an','of','and','or','in','on','to','is','are','for','with','my','what','how','why','do','i','it','this','that','কি','কী','কেন','কিভাবে','কীভাবে','আমার','এ','ও','এবং','হয়','হলে','করব','করবো','করতে','আছে','না','কোন','কোনো','যে','এই','সে','তে','থেকে','জন্য','দিয়ে','বা'];
    foreach ($m[0] as $t) {
        if (in_array($t, $stop, true)) continue;
        $out[] = $t;
        $st = kb_stem($t);
        if ($st !== $t) $out[] = $st;
    }
    return $out;
}

function kb_stem(string $t): string {
    if (mb_strlen($t) <= 3) return $t;
    foreach (['গুলোতে','গুলোর','গুলো','দের','য়ের','েরা','ের','এর','তে','কে','টি','টা','রা','র','ে','য়'] as $suf) {
        if (mb_strlen($t) - mb_strlen($suf) >= 2 && mb_substr($t, -mb_strlen($suf)) === $suf) return mb_substr($t, 0, mb_strlen($t) - mb_strlen($suf));
    }
    if (preg_match('/^[a-z]+$/', $t) && strlen($t) > 4) return preg_replace('/(ing|es|s)$/', '', $t);
    return $t;
}

/** Split a prose block into sentences (Latin stops and the Bangla danda). */
function kb_sentences(string $s): array {
    $s = trim($s);
    if ($s === '') return [];
    $parts = preg_split('/(?<=[\.!\?।])\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

/**
 * Pack sentences into variable-size chunks.
 * A new pack starts when the running text exceeds $target, or when a
 * sentence looks like a new topical heading (treatment / management / etc.).
 */
function kb_pack_sentences(array $sents, int $target = 280, int $max = 520): array {
    if (!$sents) return [];
    $chunks = [];
    $buf = '';
    $heading = '/^(treatment|management|control|prevention|ipm|advisory|সেচ|চিকিৎসা|ব্যবস্থাপনা|প্রতিকার|প্রতিরোধ)\b/iu';
    foreach ($sents as $sent) {
        $is_head = $buf !== '' && preg_match($heading, $sent);
        $next = $buf === '' ? $sent : $buf . ' ' . $sent;
        if ($is_head || ($buf !== '' && mb_strlen($next) > $target && mb_strlen($buf) >= 80)) {
            $chunks[] = $buf;
            $buf = $sent;
        } else {
            $buf = $next;
        }
        if (mb_strlen($buf) > $max) {
            $chunks[] = $buf;
            $buf = '';
        }
    }
    if ($buf !== '') $chunks[] = $buf;
    return $chunks;
}

function kb_context_header(array $d, int $part, int $parts): string {
    $src = is_array($d['source'] ?? null) ? (string)($d['source']['title'] ?? '') : '';
    return sprintf(
        '[KB context] Title: %s / %s. Crop: %s. Type: %s. Part %d of %d. Source: %s.',
        (string)($d['title_en'] ?? ''),
        (string)($d['title_bn'] ?? ''),
        (string)($d['crop'] ?? ''),
        (string)($d['type'] ?? ''),
        $part,
        $parts,
        $src
    );
}

/** @return list<array{parent: array, header: string, text_en: string, text_bn: string}> */
function kb_chunks(): array {
    static $chunks = null;
    if ($chunks !== null) return $chunks;
    $chunks = [];
    foreach (kb_docs() as $d) {
        $en = kb_pack_sentences(kb_sentences((string)($d['text_en'] ?? '')));
        $bn = kb_pack_sentences(kb_sentences((string)($d['text_bn'] ?? '')));
        $n = max(count($en), count($bn), 1);
        if (!$en && !$bn) {
            $en = [''];
            $bn = [''];
            $n = 1;
        }
        for ($i = 0; $i < $n; $i++) {
            $header = kb_context_header($d, $i + 1, $n);
            $chunks[] = [
                'parent' => $d,
                'header' => $header,
                'text_en' => $en[$i] ?? '',
                'text_bn' => $bn[$i] ?? '',
            ];
        }
    }
    return $chunks;
}

function kb_index(): array {
    static $idx = null;
    if ($idx !== null) return $idx;
    $chunks = kb_chunks();
    $df = []; $tf = []; $len = [];
    foreach ($chunks as $i => $c) {
        $d = $c['parent'];
        $toks = array_merge(
            ...array_fill(0, 3, kb_tokens(implode(' ', $d['keywords'] ?? []))),
            ...array_fill(0, 2, kb_tokens(($d['title_en'] ?? '') . ' ' . ($d['title_bn'] ?? ''))),
            ...array_fill(0, 2, kb_tokens($c['header'])),
        );
        $toks = array_merge($toks, kb_tokens($c['text_en'] . ' ' . $c['text_bn'] . ' ' . ($d['crop'] ?? '')));
        $tf[$i] = array_count_values($toks);
        $len[$i] = count($toks);
        foreach (array_keys($tf[$i]) as $t) $df[$t] = ($df[$t] ?? 0) + 1;
    }
    $idx = ['df' => $df, 'tf' => $tf, 'len' => $len, 'avg' => $len ? array_sum($len) / count($len) : 1, 'n' => count($chunks)];
    return $idx;
}

/**
 * @return array<int, array{doc: array, score: float, chunk?: array}>
 * `doc` stays compatible with copilot.php / disease.php (id, titles, source, text_*).
 * text_en / text_bn are the matched chunk, prefixed with the contextual header.
 */
function kb_search(string $query, int $k = 4, float $min_score = 1.0): array {
    $idx = kb_index();
    $chunks = kb_chunks();
    $q = array_unique(kb_tokens($query));
    $k1 = 1.4; $b = 0.75; $scores = [];
    foreach ($idx['tf'] as $i => $tf) {
        $s = 0.0;
        foreach ($q as $t) {
            if (!isset($tf[$t])) continue;
            $idf = log(1 + ($idx['n'] - $idx['df'][$t] + 0.5) / ($idx['df'][$t] + 0.5));
            $s += $idf * ($tf[$t] * ($k1 + 1)) / ($tf[$t] + $k1 * (1 - $b + $b * $idx['len'][$i] / $idx['avg']));
        }
        if ($s >= $min_score) $scores[$i] = $s;
    }
    arsort($scores);
    $best_by_doc = [];
    foreach ($scores as $i => $s) {
        $id = $chunks[$i]['parent']['id'] ?? ('chunk-' . $i);
        if (!isset($best_by_doc[$id]) || $s > $best_by_doc[$id][0]) $best_by_doc[$id] = [$s, $i];
    }
    uasort($best_by_doc, static fn($a, $b) => $b[0] <=> $a[0]);
    $out = [];
    foreach (array_slice($best_by_doc, 0, $k, true) as $pair) {
        [$s, $i] = $pair;
        $c = $chunks[$i];
        $doc = $c['parent'];
        $doc['text_en'] = trim($c['header'] . "\n" . $c['text_en']);
        $doc['text_bn'] = trim($c['header'] . "\n" . $c['text_bn']);
        $out[] = ['doc' => $doc, 'score' => round($s, 2), 'chunk' => ['header' => $c['header'], 'part' => $i]];
    }
    return $out;
}
