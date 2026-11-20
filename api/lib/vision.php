<?php
/** চাষা ভাই — crop-photo diagnosis with Grok Vision + Structured Outputs. */
declare(strict_types=1);

const DIAG_SYSTEM_PROMPT = <<<'PROMPT'
You are the plant-health vision engine of "চাষা ভাই" (Farmer's Companion) for smallholder farmers in Bangladesh.
Look at the photo and produce a careful, honest diagnosis as JSON that matches the provided schema.

Rules:
1. First judge the photo. If it is not a plant, or too blurry/dark/far to judge, set image_quality.usable=false, disease="Unknown", confidence <= 0.2 and explain in Bangla how to retake it.
2. Identify the crop if you can (rice/ধান, potato/আলু, tomato/টমেটো, brinjal/বেগুন, maize/ভুট্টা, jute/পাট, wheat/গম, chilli/মরিচ, etc.).
3. Name the most likely problem (disease, pest damage, nutrient deficiency, abiotic stress, or "Healthy Plant"). Give a calibrated confidence 0–1: use > 0.8 only for textbook-clear symptoms; 0.4–0.7 when several causes look similar.
4. List the concrete visual_evidence you used, as short simple Bangla phrases (what you SEE: lesion shape, colour, halo, location on leaf, ooze, insects).
5. Give 1–3 differential diagnoses (look-alikes) with why they are less likely and one simple field check that would tell them apart.
6. infection_level (Bangla, e.g. "প্রায় ১৫%") / infection_level_en / infection_percent (number): realistic visual estimate of affected leaf area shown in the photo.
7. Treatment: order from immediate cultural/IPM action to preventive measures. Chemical control only as a later step and ONLY as a class of active ingredient (e.g. "triazole fungicide", "copper-based bactericide") with "registered in Bangladesh, label dose, on Krishi officer advice". NEVER recommend banned/POP pesticides (DDT, endosulfan, lindane, aldrin, heptachlor...). Never give exact dosages.
8. Bangla must be simple, standard Bangladeshi Bangla, Unicode Bengali script. English fields are short.
9. Do not invent facts about the farmer, location or weather; you only see the photo.
10. Look-alike checklist — before choosing, test the classic confusable pairs against what you SEE and say which cue decided it in visual_evidence:
   - Rice blast (spindle/diamond lesions, grey-white centre, brown border, pointed ends) vs brown spot (small round-oval brown spots, often a yellow halo, no grey centre) vs bacterial blight (wavy yellow-to-white streaks from leaf tip/edges).
   - Late blight (large irregular water-soaked or greasy dark-brown patches, often at leaf edges/tips, no rings, white downy growth on the underside in humid weather, fast spread) vs early blight (smaller dark spots with concentric "target" rings and yellow halo, older leaves first).
   - Viral mosaic (light/dark green or yellow mosaic mottling, leaf puckering/distortion, no discrete lesions) vs bacterial/fungal leaf spot (discrete necrotic spots).
   - Nutrient deficiency (uniform, symmetric pattern by leaf age) vs disease (scattered lesions).
   If two remain equally plausible, lower confidence to <= 0.6 and put the other first in differential.
PROMPT;

function diag_schema(): array {
    $s = ['type' => 'string'];
    $obj = fn(array $props) => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
    return $obj([
        'image_quality' => $obj(['usable' => ['type' => 'boolean'], 'is_plant' => ['type' => 'boolean'], 'issue_bn' => $s]),
        'crop' => $s, 'crop_bn' => $s,
        'disease_detection' => $obj([
            'disease' => $s, 'disease_bn' => $s, 'category' => ['type' => 'string', 'enum' => ['fungal', 'bacterial', 'viral', 'oomycete', 'pest', 'nutrient', 'abiotic', 'healthy', 'unknown']],
            'confidence' => ['type' => 'number'],
            'infection_level' => $s, 'infection_level_en' => $s, 'infection_percent' => ['type' => 'number'],
            'symptoms' => $s, 'symptoms_en' => $s, 'warning' => $s, 'warning_en' => $s,
        ]),
        'visual_evidence' => ['type' => 'array', 'items' => $s],
        'differential' => ['type' => 'array', 'items' => $obj(['disease' => $s, 'disease_bn' => $s, 'why_less_likely_bn' => $s, 'field_check_bn' => $s])],
        'required_nutrients' => ['type' => 'array', 'items' => $obj(['element' => $s, 'benefit_bn' => $s, 'benefit_en' => $s])],
        'land_management' => $obj(['soil_ph' => $s, 'soil_ph_instruction_bn' => $s, 'crop_rotation_bn' => $s, 'organic_inputs_bn' => $s, 'drainage_bn' => $s]),
        'treatment_plan' => $obj(['steps' => ['type' => 'array', 'items' => $obj(['step' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['immediate', 'cultural', 'biological', 'chemical', 'preventive']], 'instruction_bn' => $s, 'instruction_en' => $s])]]),
        'kb_query' => $s,
    ]);
}

/** @return array{ok:bool, data?:array, error?:string, usage?:array, model?:string, ms?:int} */
function diagnose_image(string $data_url, string $crop_hint = ''): array {
    $user_text = 'Diagnose this crop photo. Return JSON only. kb_query = a short English+Bangla search query naming the likely problem and crop.';
    if ($crop_hint !== '') $user_text .= ' Farmer says the crop is: ' . $crop_hint . ' (verify from the image).';
    $r = grok_call([
        'model' => cfg('GROK_VISION_MODEL'), 'temperature' => 0.2, 'max_tokens' => 2200,
        'messages' => [
            ['role' => 'system', 'content' => DIAG_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => $data_url, 'detail' => 'high']],
                ['type' => 'text', 'text' => $user_text],
            ]],
        ],
    ], diag_schema(), 'crop_diagnosis');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'http_code' => $r['http_code']];
    $j = extract_json($r['content']);
    if (!$j) return ['ok' => false, 'error' => 'Model returned invalid JSON'];
    return ['ok' => true, 'data' => $j, 'usage' => $r['usage'], 'model' => $r['model'], 'ms' => $r['ms']];
}

/** Validate and decode an image data URL. Returns [mime, bytes] or calls json_error. */
function validate_image_data_url(string $url, int $max_bytes): array {
    if (!preg_match('#^data:(image/(?:jpeg|jpg|png|webp));base64,([A-Za-z0-9+/=\r\n]+)$#', $url, $m)) json_error(400, 'ছবির ধরন সমর্থিত নয়। JPG, PNG বা WEBP দিন। (Unsupported image)');
    $bin = base64_decode($m[2], true);
    if ($bin === false) json_error(400, 'ছবি পড়া যায়নি। (Invalid base64)');
    if (strlen($bin) > $max_bytes) json_error(413, 'ছবি অনেক বড় (সর্বোচ্চ ' . round($max_bytes / 1048576) . ' MB)।');
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) json_error(400, 'ফাইলটি আসল ছবি নয়। (Not a valid image)');
    if ($info[0] < 64 || $info[1] < 64) json_error(400, 'ছবি অনেক ছোট — অন্তত ৬৪×৬৪ পিক্সেল দিন।');
    return [$info['mime'], $bin, $info[0], $info[1]];
}
