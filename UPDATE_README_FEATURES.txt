Chasha Bhai v2 — data-layer feature update (20 Nov 2026)

Copy every file in this zip over the project, keeping the folder paths
(on the PC: C:\Users\hp\chasha_bhai_v2, and on the live host's public_html).
This zip was built from the latest PC copy, so index.html, sw.js and api/copilot.php
in it already contain your later wording; nothing farmer-facing was reworded.

New files
  api/feedback.php            farmer feedback (ঠিক / ভুল / জানি না) + aggregate stats
  api/data_export.php         GeoJSON / CSV / JSON / NDJSON exports
  api/copilot_stream.php      কৃষি পরামর্শ streaming over Server-Sent Events
  api/lib/copilot_core.php    shared agent loop + answer cache + streaming model call
  api/lib/store.php           SQLite (PDO) store, JSON-lines fallback, retention
  api/lib/webhook.php         optional HMAC-signed outbound webhook
  assets/data-enhance.js      feedback buttons + live streaming progress in the chat
  eval/test_offline.php       27 offline checks (php eval/test_offline.php)
  scripts/export_dataset.py   labelled dataset -> CSV + Parquet
  scripts/requirements.txt
  .github/workflows/ci.yml    lint + offline tests + secret scan on every push
Changed files
  api/copilot.php (now a thin wrapper over copilot_core.php), api/disease.php, api/status.php,
  api/bootstrap.php, api/config.sample.php, index.html (one script tag), sw.js (VER chasha-v2-7),
  .htaccess, router.php, .gitignore, README.md, AGENTS.md, docs/PRD.md, mcp/server.py

After copying
  1. Open https://hackathon.cursol.pro/api/status.php -> "data_layer.storage" should say "SQLite (PDO)".
  2. Hard-refresh the app (Ctrl+F5). Ask a question in কৃষি পরামর্শ: the bubble now fills in live.
  3. Scan a photo: the result card shows "এই ফলাফল কি ঠিক মনে হচ্ছে?".
  4. Optional config (env or chasha_config.php): WEBHOOK_URL, WEBHOOK_SECRET, EXPORT_TOKEN.
  5. data/runtime/ must stay writable (it already is for the caches).
Requires SQLite 3.24+ for the feedback upsert (any host from 2018 on); otherwise set STORE_DRIVER=json.
