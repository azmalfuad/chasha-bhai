# চাষা ভাই · Chasha Bhai v2

**The Bangla farm copilot that checks its own answer.** You photograph a leaf and Chasha Bhai diagnoses it with Grok vision. It then checks that verdict against **live weather** (from Open-Meteo, scored with BMD thresholds) and against **Sentinel-2 satellite health of your own drawn field**. This is the *Three-Eye Check* (তিন-চোখ যাচাই). Every answer is explainable, cited, speakable in Bangla, and works offline as an installable PWA.

| Part | What it is | Status |
|---|---|---|
| `index.html` + `assets/` | Main web app (original look, extended) | ✅ working |
| `api/*.php` | PHP 8 API: disease, weather, geocode, ndvi proxy, copilot (JSON + SSE streaming), outbreak, feedback, data export, status | ✅ working |
| `api/lib/store.php` | SQLite (PDO) store for anonymous diagnoses + farmer feedback, JSON-lines fallback | ✅ tested offline |
| `services/satellite/` | FastAPI Sentinel-2 NDVI/NDWI service (Docker) | ✅ working (needs separate host) |
| `mcp/server.py` | MCP server with 8 tools, stdio + Streamable HTTP | ✅ tested with the MCP Python SDK client over stdio and Streamable HTTP |
| `docs/index.html` | Pitch deck, whitepaper and live status dashboard | ✅ |
| `eval/` | Reproducible evaluation harness and honest results | ✅ (small n) |
| `SUBMISSION.md` | Ready-to-paste hackathon form answers | ✅ |

## 1. Requirements
- **Web host:** PHP **8.0+** with `curl`, `json`, `mbstring`, `gd` (or `fileinfo`); `pdo_sqlite` is recommended for the feedback store (a JSON-lines fallback is used without it). Apache with `.htaccess` (cPanel shared hosting is fine). HTTPS is required, because the camera, GPS, mic and service worker need a secure context.
- **Satellite service:** any Docker host. Render's free plan and Railway both work. Python 3.11+ with rasterio if you run it without Docker.
- **xAI API key:** from https://console.x.ai.

## 2. Deploy the PHP site (shared hosting / cPanel)
1. Upload everything **except** `services/`, `mcp/`, `eval/` and `*.md` into `public_html/`. Uploading them is optional, because `.htaccess` blocks them from the web anyway.
2. **Set the secrets.** Never put them inside `public_html`.
   - **Preferred:** use cPanel → *Setup Environment Variables*, or `SetEnv` in a vhost.
   - **Otherwise:** copy `api/config.sample.php` to **`/home/USER/chasha_config.php`**. That is one level *above* `public_html`, which `api/bootstrap.php` reads as `../chasha_config.php` relative to the web root. Then fill it in.
3. Make `data/runtime/` writable by PHP (`chmod 755`, or 775 if your host needs it). It stores caches, rate-limit buckets, usage logs and the anonymous outbreak grid. It is blocked by `data/.htaccess`.
4. Open `https://your-domain/api/status.php`. Every check should be `ok: true`, and `grok_key_set` should be `true`.

### Environment variables
| Variable | Default | Meaning |
|---|---|---|
| `GROK_API_KEY` | — (required) | xAI key, server-side only |
| `GROK_VISION_MODEL` | `grok-4.20-non-reasoning` | photo diagnosis |
| `GROK_FAST_MODEL` | `grok-4.20-non-reasoning` | weather explanation, short copilot answers |
| `GROK_REASON_MODEL` | `grok-4.3` | complex copilot questions |
| `GROK_SSL_VERIFY` | `true` | keep `true` |
| `DEBUG` | `false` | adds upstream error details to JSON errors |
| `ALLOWED_ORIGINS` | empty (same-origin) | comma-separated CORS allow-list |
| `SAT_SERVICE_URL` | empty | e.g. `https://chasha-satellite.onrender.com` |
| `RATE_LIMIT_PER_MIN` | `20` | per IP, per endpoint |
| `AI_RATE_LIMIT_PER_DAY` | `60` | paid AI calls per IP per day |
| `AI_GLOBAL_PER_DAY` | `0` (off) | cap on AI calls per day across ALL visitors — set e.g. `300` on a public demo |
| `TRUST_LOCAL_PROXY` | `false` | `true` only when a tunnel (e.g. cloudflared) runs on the same host, so per-visitor limits use `CF-Connecting-IP` |
| `MAX_IMAGE_BYTES` | `8388608` | upload cap |
| `STORE_DRIVER` | `auto` | `auto` = SQLite via PDO when `pdo_sqlite` exists, else JSON-lines files; `json` forces files |
| `STORE_RETENTION_DAYS` | `180` | anonymous diagnosis/feedback rows are deleted after this |
| `ANSWER_CACHE_TTL` | `10800` | advice-chat answer cache in seconds (`0` = off); weather-dependent answers expire after 30 min |
| `WEBHOOK_URL` / `WEBHOOK_SECRET` | empty | optional outbound webhook for `outbreak.cell_alert`, HMAC-SHA256 signed |
| `EXPORT_TOKEN` | empty | enables the row-level labelled-dataset export; empty = disabled |

## 3. Deploy the satellite service
**Render:** push the repo to GitHub, then choose *New → Blueprint* and point it at `services/satellite/render.yaml`. Alternatively, create a *Docker Web Service* with root dir `services/satellite`. Set `ALLOWED_ORIGINS=https://your-domain`. Copy the service URL into `SAT_SERVICE_URL` on the PHP host.

**Railway:** *New → Deploy from repo* with root `services/satellite`. Railway detects the Dockerfile, and `PORT` is honoured.

**Local:**
```bash
cd services/satellite && python -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt && uvicorn main:app --port 8001
curl "http://127.0.0.1:8001/ndvi?lat=24.43&lon=88.70&radius_m=60"
```
The service uses only open data: Element84 Earth Search STAC and Sentinel-2 L2A COGs on AWS, unsigned, with no key needed.

## 4. Run everything locally
```bash
export GROK_API_KEY=...           # env only — never write it into a file inside the project
export SAT_SERVICE_URL=http://127.0.0.1:8001
php -S 127.0.0.1:8080 router.php  # router.php mirrors the .htaccess deny rules
# open http://127.0.0.1:8080  ·  docs at /docs/  ·  status at /api/status.php
```

## 5. MCP server
```bash
pip install -r mcp/requirements.txt
CHASHA_API_BASE=https://your-domain/api python mcp/server.py   # stdio transport
```
Claude Desktop / Cursor config example:
```json
{ "mcpServers": { "chasha-bhai": { "command": "python", "args": ["/path/mcp/server.py"], "env": { "CHASHA_API_BASE": "https://your-domain/api" } } } }
```
Streamable HTTP transport (for remote MCP hosts): `python mcp/server.py --transport streamable-http --host 127.0.0.1 --port 8765` serves MCP at `http://127.0.0.1:8765/mcp`. The legacy HTTP+SSE transport is also available with `--transport sse` (endpoint `/sse`).

The tools are `diagnose_crop`, `get_agri_weather`, `get_field_ndvi`, `search_krishi_kb`, `get_outbreak_alerts`, `submit_diagnosis_feedback`, `get_feedback_stats` and `export_outbreak_geojson`. The MCP server never holds the xAI key; it calls the public API.

## 6. Evaluation
```bash
php eval/test_offline.php                                # 27 offline checks, no AI key or network needed
python eval/run_eval.py --api http://127.0.0.1:8080/api   # writes eval/results/results.json + use EVAL.md
```
`.github/workflows/ci.yml` runs `php -l` on every PHP file, the offline tests, a Python compile check and a secret scan on each push.
See `eval/EVAL.md`. The sample is small, so treat the results as a smoke test, not an accuracy claim.

## 6b. Data layer (feedback loop, storage, streaming, exports)
- **Diagnosis log + farmer feedback.** `api/disease.php` writes one anonymous row per diagnosis (crop, label, category, confidences, agreement, escalation, which eyes were used, 0.1° grid cell). No photo, IP, name, phone or exact GPS. The result card shows “ঠিক / ভুল / জানি না” (`assets/data-enhance.js`), which POSTs to `api/feedback.php`; a “ভুল” answer can carry the correct label. `GET api/feedback.php?stats=1` returns counts only. These are farmer-reported verdicts, not a verified accuracy figure.
- **Storage.** `api/lib/store.php` uses SQLite through PHP PDO (`data/runtime/chasha.sqlite`, tables `diagnoses`, `feedback`, `webhook_log`). If `pdo_sqlite` is missing it falls back to JSON-lines files under `data/runtime/store/`. Rows older than `STORE_RETENTION_DAYS` are deleted by the retention sweep.
- **Streaming chat.** `api/copilot_stream.php` runs the same agent loop as `api/copilot.php` (shared code in `api/lib/copilot_core.php`), calls the model with `stream=true`, and sends Server-Sent Events: `status`, `tool`, `partial` (banned names masked, invalid `[n]` dropped) and `final` (the same validated JSON as `copilot.php`). The PWA reads it with `fetch()` streaming and falls back to `copilot.php` if streaming is unavailable.
- **Answer cache.** First-turn chat questions are cached by a normalised question (case, spacing, punctuation, Bangla digits), model tier, ~11 km cell and app context, for `ANSWER_CACHE_TTL` (30 min if the weather tool was used). A cache hit costs zero model tokens. This is exact-match after normalisation, not embedding-based semantic caching.
- **Exports.** `api/data_export.php?dataset=outbreak&format=geojson|csv|json` (published k-anonymous cells only, GeoJSON polygons), `dataset=feedback_stats&format=csv|json`, and `dataset=labelled&format=csv|ndjson|json&token=…` (disabled unless `EXPORT_TOKEN` is set). `python scripts/export_dataset.py --formats csv,parquet` builds the same labelled dataset as CSV and Apache Parquet (needs `pyarrow`).
- **Webhooks.** With `WEBHOOK_URL` set, an `outbreak.cell_alert` event is POSTed once per cell/level/day when a published cell becomes ACTIVE or RISING. The body is signed: `X-Chasha-Signature: sha256=HMAC(WEBHOOK_SECRET, body)`.

## 7. Security checklist
- **The key that was in the original `api/disease.php` / `api/weather.php` was exposed. ROTATE IT in the xAI console before deploying.**
- `test.php` was removed, and `api/.htaccess` returns 404 for it if an old copy is still on the server. **Delete it from your live server.**
- SSL verification is on and debug is off. CORS uses an allow-list. Rate limits apply per IP. Images are validated (MIME, base64, decodable, ≥ 64 px).
- `.htaccess` denies `api/lib/`, `api/bootstrap.php`, configs, `data/`, `services/`, `eval/`, `mcp/`, `scripts/`, `*.md`/`*.py` and SQLite files.
- The outbreak map stores no photo, IP or exact GPS: only `{disease, crop, date, 0.1° cell}`. A cell is shown only when it has ≥ 3 reports.

## 8. Data & licences
- **Weather:** Open-Meteo, CC BY 4.0.
- **Satellite:** contains modified Copernicus Sentinel data, via Element84 Earth Search / AWS Open Data.
- **Base maps:** Esri World Imagery and © OpenStreetMap contributors.
- **Knowledge base:** short paraphrased summaries with links to IRRI Rice Knowledge Bank, FAO, WHO, the Stockholm Convention, BMD and AIS (16123). See `data/kb/krishi_kb.json`.
- **Eval images:** Wikimedia Commons; per-file licences are in `eval/samples/manifest.json`.
