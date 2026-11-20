<?php
/**
 * চাষা ভাই — output guardrails.
 *  1. Pesticide guard: scans AI text for (a) Stockholm-Convention POP pesticides -> removed,
 *     (b) WHO hazard class Ia/Ib examples -> kept but flagged with a strong warning.
 *  2. Numeric grounding check: every number in AI text must exist in the facts given to it.
 *  3. Escalation policy: when to tell the farmer to call 16123 / visit the Upazila office.
 */
declare(strict_types=1);

// Persistent organic pollutant pesticides (Stockholm Convention Annex A/B examples).
const POP_PESTICIDES = ['ddt', 'aldrin', 'dieldrin', 'endrin', 'chlordane', 'heptachlor', 'mirex', 'toxaphene',
    'lindane', 'endosulfan', 'chlordecone', 'hexachlorobenzene', 'pentachlorophenol',
    'ডিডিটি', 'অলড্রিন', 'অ্যালড্রিন', 'ডাইএলড্রিন', 'ডিয়েলড্রিন', 'এনড্রিন', 'ক্লোরডেন', 'হেপ্টাক্লোর', 'মিরেক্স', 'টক্সাফেন', 'লিনডেন', 'লিন্ডেন', 'এন্ডোসালফান'];
// WHO Recommended Classification of Pesticides by Hazard (2019): examples of class Ia / Ib actives.
const WHO_IA_IB = ['parathion', 'methyl parathion', 'phorate', 'terbufos', 'disulfoton', 'mevinphos', 'aldicarb',
    'monocrotophos', 'carbofuran', 'dichlorvos', 'methomyl', 'triazophos', 'zinc phosphide', 'methamidophos', 'omethoate',
    'প্যারাথিয়ন', 'ফোরেট', 'কার্বোফুরান', 'মনোক্রোটোফস', 'ডাইক্লোরভস', 'মিথোমাইল', 'জিংক ফসফাইড'];

/** Recursively scan & sanitize strings in $data. Returns [sanitized, findings]. */
function pesticide_guard($data, array &$findings = []) {
    if (is_array($data)) { foreach ($data as $k => $v) $data[$k] = pesticide_guard($v, $findings); return $data; }
    if (!is_string($data)) return $data;
    $hit = false;
    foreach (POP_PESTICIDES as $p) {
        $re = '/(?<![\p{L}])' . preg_quote($p, '/') . '(?![\p{L}])/iu';
        if (preg_match($re, $data)) {
            $findings[] = ['level' => 'blocked', 'substance' => $p, 'reason' => 'Persistent organic pollutant under the Stockholm Convention'];
            $data = preg_replace($re, '[নিষিদ্ধ রাসায়নিক]', $data); $hit = true;
        }
    }
    if ($hit) $data .= ' ⚠ (স্টকহোম কনভেনশনে নিষিদ্ধ স্থায়ী দূষক বালাইনাশক ব্যবহার করবেন না — কৃষি কর্মকর্তার পরামর্শ নিন)';
    $low = mb_strtolower($data);
    foreach (WHO_IA_IB as $p) {
        if (preg_match('/(?<![\p{L}])' . preg_quote($p, '/') . '(?![\p{L}])/u', $low)) {
            $findings[] = ['level' => 'warning', 'substance' => $p, 'reason' => 'WHO hazard class Ia/Ib (extremely/highly hazardous)'];
            $data .= ' ⚠ (অতি বিপজ্জনক রাসায়নিক — শুধুমাত্র নিবন্ধিত হলে ও কৃষি কর্মকর্তার পরামর্শে, পূর্ণ সুরক্ষা পোশাকে)';
            break;
        }
    }
    return $data;
}

function numbers_in(string $s): array {
    $s = strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
    preg_match_all('/\d+(?:\.\d+)?/', $s, $m);
    return array_map('floatval', $m[0]);
}

/**
 * Numeric grounding: return numbers in AI text that do not appear (±0.6 or rounded) in facts.
 * Small integers 0–10 and window constants are allowed (counts like "৩ দিন").
 */
function grounding_check(array $ai_texts, $facts, array $allowed = [24, 48, 72, 144, 16123]): array {
    $fact_nums = numbers_in(json_encode($facts, JSON_UNESCAPED_UNICODE));
    $bad = []; $checked = 0;
    foreach ($ai_texts as $t) foreach (numbers_in((string)$t) as $n) {
        $checked++;
        if ($n <= 10 || in_array($n, $allowed, false)) continue;
        $ok = false;
        foreach ($fact_nums as $f) if (abs($f - $n) <= 0.6) { $ok = true; break; }
        if (!$ok) $bad[] = $n;
    }
    return ['numbers_checked' => $checked, 'unverified' => array_values(array_unique($bad)), 'grounded' => !$bad];
}

function safety_block(): array {
    return [
        'hotline' => '16123', 'hotline_label_bn' => 'কৃষি কল সেন্টার ১৬১২৩ (কৃষি তথ্য সার্ভিস)',
        'ppe_bn' => 'স্প্রের সময় গ্লাভস, মাস্ক, লম্বা হাতা জামা ও বুট পরুন; বাতাসের দিকে পিঠ রেখে স্প্রে করুন; শিশুদের দূরে রাখুন।',
        'label_bn' => 'শুধু বাংলাদেশে নিবন্ধিত বালাইনাশক, লেবেলের মাত্রা ও ফসল তোলার আগে অপেক্ষার সময় (PHI) মেনে ব্যবহার করুন।',
        'ipm_bn' => 'আগে অ-রাসায়নিক উপায় (আইপিএম): আক্রান্ত অংশ সরানো, ফাঁদ, উপকারী পোকা রক্ষা, সুষম সার।',
    ];
}
