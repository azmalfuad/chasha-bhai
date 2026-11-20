"""Chasha Bhai MCP server — exposes the farm-advisory stack as Model Context Protocol tools.

Any MCP client (Claude Desktop, Cursor, Grok-compatible agents, …) can call:
  diagnose_crop      – leaf photo → Three-Eye verdict (photo + weather + satellite)
  get_agri_weather   – Open-Meteo forecast + deterministic BMD-threshold farm risks
  get_field_ndvi     – Sentinel-2 NDVI/NDWI time series for a point/field in Bangladesh
  search_krishi_kb   – BM25 search over the curated, cited Krishi knowledge base
  get_outbreak_alerts– k-anonymous community disease hotspots

It is a thin client over the public PHP API, so it never holds the xAI key.
Run:  CHASHA_API_BASE=https://your-site/api python mcp/server.py      (stdio transport)
"""
from __future__ import annotations

import base64
import mimetypes
import os
from typing import Any, Optional

import httpx
try:  # MCP Python SDK 2.x
    from mcp.server.mcpserver import MCPServer as FastMCP
except ImportError:  # SDK 1.x
    from mcp.server.fastmcp import FastMCP

API = os.getenv("CHASHA_API_BASE", "http://127.0.0.1:8080/api").rstrip("/")
TIMEOUT = float(os.getenv("CHASHA_TIMEOUT", "120"))
mcp = FastMCP("chasha-bhai")


def _get(path: str, **params: Any) -> dict:
    r = httpx.get(f"{API}/{path}", params={k: v for k, v in params.items() if v is not None}, timeout=TIMEOUT)
    return r.json() if r.headers.get("content-type", "").startswith("application/json") else {"error": f"HTTP {r.status_code}"}


def _post(path: str, body: dict) -> dict:
    r = httpx.post(f"{API}/{path}", json=body, timeout=TIMEOUT)
    return r.json() if r.headers.get("content-type", "").startswith("application/json") else {"error": f"HTTP {r.status_code}"}


@mcp.tool()
def diagnose_crop(image_path: Optional[str] = None, image_data_url: Optional[str] = None,
                  lat: Optional[float] = None, lon: Optional[float] = None, crop_hint: Optional[str] = None) -> dict:
    """Diagnose a crop disease/pest/deficiency from a leaf photo (Bangla + English output).
    Give image_path (local file) or image_data_url. Optional lat/lon (Bangladesh) adds the live-weather check."""
    if image_path:
        mime = mimetypes.guess_type(image_path)[0] or "image/jpeg"
        with open(image_path, "rb") as f:
            image_data_url = f"data:{mime};base64," + base64.b64encode(f.read()).decode()
    if not image_data_url:
        return {"error": "image_path or image_data_url is required"}
    body: dict[str, Any] = {"image": image_data_url}
    if lat is not None and lon is not None:
        body.update(lat=lat, lon=lon)
    if crop_hint:
        body["crop_hint"] = crop_hint
    d = _post("disease.php", body)
    if "error" in d:
        return d
    return {k: d.get(k) for k in ("crop", "disease_detection", "fusion", "differential", "treatment_plan", "references", "safety", "meta")}


@mcp.tool()
def get_agri_weather(location: Optional[str] = None, lat: Optional[float] = None, lon: Optional[float] = None,
                     crop: Optional[str] = None) -> dict:
    """Farm weather for a Bangladesh place (division name like 'Rajshahi', or lat/lon): current conditions,
    7-day forecast, rule-based risks (thunderstorm, heavy rain, heatwave, cold/fog, disease weather),
    spray windows and FAO-56 irrigation balance. Deterministic — no AI in the numbers."""
    d = _get("weather.php", location=location, lat=lat, lon=lon, crop=crop, advisory=0)
    return d


@mcp.tool()
def get_field_ndvi(lat: float, lon: float, radius_m: float = 60, days: int = 120) -> dict:
    """Sentinel-2 (10 m) crop-health time series for a circular field in Bangladesh: NDVI, NDWI, NDMI,
    neighbour benchmark (field-specific vs regional stress) and a plain-language interpretation."""
    d = _get("ndvi.php", lat=lat, lon=lon, radius_m=radius_m, days=days)
    d.pop("ndvi_thumbnail_png", None)
    return d


@mcp.tool()
def search_krishi_kb(query: str, k: int = 3) -> dict:
    """Search the curated bilingual (Bangla/English) agriculture knowledge base (IRRI, FAO, WHO, BMD, AIS …).
    Returns passages with source URLs for citation."""
    from pathlib import Path
    import json, math, re
    kb = json.loads((Path(__file__).resolve().parent.parent / "data/kb/krishi_kb.json").read_text(encoding="utf-8"))
    docs = kb["docs"] if isinstance(kb, dict) else kb
    tok = lambda s: [t for t in re.findall(r"[\w\u0980-\u09FF]+", s.lower()) if len(t) > 1]
    toks = [tok(" ".join([d.get("title_en", ""), d.get("title_bn", ""), " ".join(d.get("keywords", [])), d.get("text_en", ""), d.get("text_bn", "")])) for d in docs]
    N, avg = len(docs), sum(map(len, toks)) / max(1, len(docs))
    q = tok(query)
    def score(t):
        s = 0.0
        for w in set(q):
            df = sum(1 for x in toks if any(y.startswith(w[:5]) for y in x))
            tf = sum(1 for y in t if y.startswith(w[:5]))
            if tf:
                s += math.log(1 + (N - df + .5) / (df + .5)) * tf * 2.2 / (tf + 1.2 * (.25 + .75 * len(t) / avg))
        return s
    ranked = sorted(((score(t), d) for t, d in zip(toks, docs)), key=lambda x: -x[0])[:k]
    return {"query": query, "results": [{"id": d["id"], "title_en": d.get("title_en"), "title_bn": d.get("title_bn"),
             "text_en": d.get("text_en"), "source": d.get("source"), "score": round(s, 3)} for s, d in ranked if s > 0]}


@mcp.tool()
def get_outbreak_alerts(lat: Optional[float] = None, lon: Optional[float] = None, days: int = 21) -> dict:
    """Community disease hotspots from anonymous scans (0.1° grid, shown only when ≥3 reports — k-anonymity)."""
    return _get("outbreak.php", lat=lat, lon=lon, days=days)


if __name__ == "__main__":
    mcp.run()
