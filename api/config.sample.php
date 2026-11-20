<?php
/**
 * চাষা ভাই — configuration TEMPLATE (safe to commit; contains no secrets).
 *
 * Preferred: set environment variables (cPanel "Environment Variables", Apache SetEnv
 * in a vhost you control, Render/Railway dashboard...). Any key below can be an env var.
 *
 * Alternative for shared hosting without env vars:
 *   copy this file to  ../chasha_config.php   (ONE LEVEL ABOVE public_html — best)
 *   or to              api/config.local.php    (git-ignored and blocked by api/.htaccess)
 * and fill in the values.
 */
return [
    'GROK_API_KEY'          => '',   // xAI key — NEVER commit a real one
    'GROK_VISION_MODEL'     => 'grok-4.20-non-reasoning',
    'GROK_FAST_MODEL'       => 'grok-4.20-non-reasoning',
    'GROK_REASON_MODEL'     => 'grok-4.3',
    'GROK_SSL_VERIFY'       => true,     // keep true in production
    'DEBUG'                 => false,    // true adds upstream error details to JSON errors
    'ALLOWED_ORIGINS'       => '',       // e.g. 'https://chashabhai.example.com' ; empty = same origin only
    'SAT_SERVICE_URL'       => '',       // e.g. 'https://chasha-sat.onrender.com'
    'RATE_LIMIT_PER_MIN'    => 20,
    'AI_RATE_LIMIT_PER_DAY' => 60,
];
