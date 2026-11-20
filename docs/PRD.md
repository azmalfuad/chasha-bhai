# Product requirements — চাষা ভাই v2

Status: living PRD for Team Syntax Error!, Cloud Camp BD Idea to Unicorn 2026. This is a working document for builders, not a public marketing page.

## Problem

Smallholder farmers in Bangladesh lose yield to diseases and pests that are often treatable when caught early. Expert advice arrives slowly (neighbour, input shop, 16123). Photo-only “AI doctors” are often English-first, over-confident, and ignore weather and the rest of the field. Weather apps show icons, not spray windows. Chatbots invent doses and prices.

## Target user

A Bangladeshi smallholder with a basic Android phone, a slow network, and Bangla as the first language. No account should be required.

## Goals

1. Diagnose a leaf photo with a strict, explainable schema (crop, problem, evidence, differential, IPM-first plan).
2. Verify that verdict with the Three-Eye Check: photo + live weather support + Sentinel-2 field health versus a neighbour ring.
3. Turn BMD-threshold weather into plain actions (spray windows, FAO-56 irrigation balance). The model only explains numbers the server already computed.
4. Answer follow-up questions in কৃষি পরামর্শ with cited, in-scope Bangla, and speak the answer when the device has a Bangla voice.
5. Work as an installable offline-first PWA. Last diagnosis and weather remain readable offline.
6. Keep the farmer-facing UI free of jargon (সবুজতা স্কোর 0–100, not “NDVI”) and free of AI vendor or model names.
7. Stay honest: publish eval misses; no invented pilots, partners, or accuracy claims beyond `eval/EVAL.md`.
8. Close the data loop: every diagnosis can be marked ঠিক / ভুল / জানি না by the farmer, stored anonymously, and exported as a labelled set (CSV / NDJSON / Parquet) to grow the evaluation set. Farmer verdicts are reported as self-reported counts, never as accuracy.

## Non-goals (current release)

- Training or fine-tuning a vision model on Bangladeshi field photos (needs a partner dataset).
- A vector database, embedding-based semantic cache, or Graph RAG (the knowledge base is 17 curated documents; BM25 is enough).
- Market prices, local LLM runtimes (Ollama and similar), n8n/workflow engines, or a farmer-facing analytics warehouse.
- A native Android/iOS store app (the PWA is the client).

## Experience requirements

- Camera capture or upload, client-side downscale to ≤ 1280 px JPEG, Bangla result card, optional voice read-out.
- Map: draw the field or drop a pin; show greenness history (Chart.js) and a CSV export of the same series.
- Doubtful or severe cases escalate to 16123.
- Optional, off-by-default community outbreak sharing with k-anonymity.
- The advice chat streams: tool steps and the answer appear while they are produced; the final text is the server-validated one.
- One-tap feedback under the diagnosis card; no account, no phone number.

## Technical requirements

- PHP 8 API + FastAPI satellite service. Local ports: 8080 and 8001.
- Structured Outputs for diagnosis and weather text. Tool-calling loop (max 3 rounds) for the advice chat. BM25 retrieval with contextual chunk headers.
- Open data only for weather and satellite (Open-Meteo, Copernicus Sentinel-2).
- Secrets off the web root. Rate limits. SSL verify on.
- Storage: SQLite via PDO for anonymous diagnoses and feedback (JSON-lines fallback), retention 180 days. No Postgres, Redis, object store or vector DB.
- Streaming: Server-Sent Events from `api/copilot_stream.php` (model called with `stream=true`).
- Token optimisation: static prompts (provider prompt caching), model routing, last-6-turn window, and a normalised exact-match answer cache.
- Exports: GeoJSON / CSV / JSON outbreak cells, CSV / NDJSON / Parquet labelled dataset; optional HMAC-signed outbound webhook.
- MCP: stdio and Streamable HTTP transports, 8 tools.

## Quality bar

- `php -l` on PHP changes and `php eval/test_offline.php` (runs in CI, `.github/workflows/ci.yml`). Playwright or a manual desktop + mobile pass for the main flow.
- `eval/run_eval.py` against a live API. Current honest result (19 Nov 2026): top-1 6/11 on 11 reference photos; 7/7 advisor guardrail probes after one prompt fix; weather text grounded at 5/5 places. This is a smoke test, not an accuracy claim.

## Roadmap (not current results)

A first district pilot, a larger labelled field-photo set with a research or extension partner, and a privacy-preserving field-health share link for relatives abroad.
