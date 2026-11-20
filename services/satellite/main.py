"""
চাষা ভাই — Satellite Crop-Health Microservice (FastAPI)

Free, no-key path:
  * Scene search: Element84 Earth Search STAC v1 (collection sentinel-2-l2a)
    https://earth-search.aws.element84.com/v1
  * Pixels: Cloud-Optimised GeoTIFFs on the public AWS Open Data bucket
    (sentinel-cogs), read with HTTP range requests (windowed read — only the
    few KB covering the farmer's field are downloaded, never the 100 MB tile).

Endpoints
  GET  /health
  GET  /ndvi?lat=..&lon=..&radius_m=60&days=120     (circle around a point)
  POST /ndvi  {"geometry": <GeoJSON Polygon>, "days": 120}

Everything returned is computed from real Sentinel-2 L2A pixels. Clouds,
shadows, snow and no-data are masked with the Scene Classification Layer (SCL).
Nothing is ever interpolated or invented: if no clear scene exists, the
service says so.
"""
from __future__ import annotations

import base64
import io
import math
import os
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from typing import Any, Optional

import httpx
import numpy as np
import rasterio
from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from rasterio.features import geometry_mask
from rasterio.warp import transform_geom
from rasterio.windows import from_bounds
from rasterio.enums import Resampling

STAC_URL = os.getenv("STAC_URL", "https://earth-search.aws.element84.com/v1/search")
COLLECTION = os.getenv("STAC_COLLECTION", "sentinel-2-l2a")
MAX_SCENES = int(os.getenv("MAX_SCENES", "8"))
MAX_AREA_HA = float(os.getenv("MAX_AREA_HA", "50"))  # protect the service from huge polygons
ALLOWED_ORIGINS = [o.strip() for o in os.getenv("ALLOWED_ORIGINS", "*").split(",") if o.strip()]
# Bangladesh bounding box (+ small margin). The service is scoped to BD on purpose.
BD_BBOX = (87.9, 20.5, 92.8, 26.8)

# GDAL tuning for fast, cheap HTTP range reads of COGs
os.environ.setdefault("GDAL_DISABLE_READDIR_ON_OPEN", "EMPTY_DIR")
os.environ.setdefault("CPL_VSIL_CURL_ALLOWED_EXTENSIONS", ".tif")
os.environ.setdefault("GDAL_HTTP_MERGE_CONSECUTIVE_RANGES", "YES")
os.environ.setdefault("GDAL_HTTP_MULTIPLEX", "YES")
os.environ.setdefault("VSI_CACHE", "TRUE")
os.environ.setdefault("AWS_NO_SIGN_REQUEST", "YES")
os.environ.setdefault("GDAL_HTTP_VERSION", "2")
os.environ.setdefault("GDAL_INGESTED_BYTES_AT_OPEN", "32768")
os.environ.setdefault("GDAL_HTTP_MAX_RETRY", "2")
os.environ.setdefault("GDAL_HTTP_RETRY_DELAY", "1")
BAND_POOL = ThreadPoolExecutor(max_workers=int(os.getenv("BAND_WORKERS", "32")))

# SCL classes treated as valid surface: 4 vegetation, 5 bare soil, 6 water, 7 unclassified(low prob cloud)
VALID_SCL = {4, 5, 6, 7}
# "Neighbour benchmark": we also read a ring of land around the field. If the
# neighbours' NDVI fell too, the change is regional (season, harvest, weather);
# if only this field fell, it is field-specific (pest, disease, water, nutrients).
NEIGHBOUR_M = float(os.getenv("NEIGHBOUR_M", "300"))

app = FastAPI(title="Chasha Bhai Satellite Service", version="2.0.0")
app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOWED_ORIGINS,
    allow_methods=["GET", "POST"],
    allow_headers=["Content-Type"],
)

_cache: dict[str, tuple[float, Any]] = {}
CACHE_TTL = 6 * 3600


class FieldRequest(BaseModel):
    geometry: dict = Field(..., description="GeoJSON Polygon in EPSG:4326")
    days: int = Field(120, ge=20, le=400)


def _circle(lat: float, lon: float, radius_m: float, n: int = 32) -> dict:
    dlat = radius_m / 111_320.0
    dlon = radius_m / (111_320.0 * math.cos(math.radians(lat)))
    ring = [[lon + dlon * math.cos(2 * math.pi * i / n), lat + dlat * math.sin(2 * math.pi * i / n)] for i in range(n)]
    ring.append(ring[0])
    return {"type": "Polygon", "coordinates": [ring]}


def _area_ha(geom: dict) -> float:
    ring = geom["coordinates"][0]
    lat0 = sum(p[1] for p in ring) / len(ring)
    kx = 111_320.0 * math.cos(math.radians(lat0))
    ky = 110_540.0
    pts = [(p[0] * kx, p[1] * ky) for p in ring]
    a = 0.0
    for (x1, y1), (x2, y2) in zip(pts, pts[1:]):
        a += x1 * y2 - x2 * y1
    return abs(a) / 2 / 10_000


def _validate_geom(geom: dict) -> None:
    if geom.get("type") != "Polygon" or not geom.get("coordinates"):
        raise HTTPException(400, "geometry must be a GeoJSON Polygon")
    ring = geom["coordinates"][0]
    if len(ring) < 4 or len(ring) > 500:
        raise HTTPException(400, "polygon must have 4..500 vertices")
    for lon, lat, *_ in ring:
        if not (BD_BBOX[0] <= lon <= BD_BBOX[2] and BD_BBOX[1] <= lat <= BD_BBOX[3]):
            raise HTTPException(400, "field must be inside Bangladesh")
    area = _area_ha(geom)
    if area > MAX_AREA_HA:
        raise HTTPException(400, f"field too large ({area:.1f} ha > {MAX_AREA_HA} ha)")


def _search(geom: dict, days: int) -> list[dict]:
    end = datetime.now(timezone.utc)
    start = end - timedelta(days=days)
    body = {
        "collections": [COLLECTION],
        "intersects": geom,
        "datetime": f"{start.strftime('%Y-%m-%dT%H:%M:%SZ')}/{end.strftime('%Y-%m-%dT%H:%M:%SZ')}",
        "limit": 60,
        "query": {"eo:cloud_cover": {"lt": 80}},
        "sortby": [{"field": "properties.datetime", "direction": "desc"}],
    }
    r = httpx.post(STAC_URL, json=body, timeout=30)
    r.raise_for_status()
    feats = r.json().get("features", [])
    # one scene per day (tiles overlap); prefer lower cloud cover
    by_day: dict[str, dict] = {}
    for f in feats:
        day = f["properties"]["datetime"][:10]
        if day not in by_day or f["properties"].get("eo:cloud_cover", 100) < by_day[day]["properties"].get("eo:cloud_cover", 100):
            by_day[day] = f
    return sorted(by_day.values(), key=lambda f: f["properties"]["datetime"], reverse=True)


# Empirically (Nov 2026) the Earth Search `sentinel-2-l2a` COGs hold BOA reflectance x 10000
# with the -1000 processing-baseline offset already removed (vegetation red ~800, NIR ~2000),
# even though the STAC metadata still advertises offset=-0.1. Applying it again yields NDVI > 1.
# Set APPLY_STAC_OFFSET=true if you switch to a collection where the offset is genuinely needed.
APPLY_STAC_OFFSET = os.getenv("APPLY_STAC_OFFSET", "false").lower() == "true"


def _scale_offset(asset: dict) -> tuple[float, float]:
    bands = asset.get("raster:bands") or [{}]
    off = float(bands[0].get("offset", 0.0)) if APPLY_STAC_OFFSET else 0.0
    return float(bands[0].get("scale", 0.0001)), off


def _read(href: str, geom: dict, out_shape: Optional[tuple[int, int]] = None, pad: float = 10):
    with rasterio.open(href) as src:
        g = transform_geom("EPSG:4326", src.crs, geom)
        xs = [p[0] for p in g["coordinates"][0]]
        ys = [p[1] for p in g["coordinates"][0]]
        win = from_bounds(min(xs) - pad, min(ys) - pad, max(xs) + pad, max(ys) + pad, src.transform)
        win = win.round_offsets().round_lengths()
        kw = {"window": win, "boundless": True, "fill_value": 0}
        if out_shape:
            kw["out_shape"] = out_shape
            kw["resampling"] = Resampling.nearest
        arr = src.read(1, **kw)
        transform = src.window_transform(win)
        if out_shape:
            transform = transform * transform.scale(win.width / out_shape[1], win.height / out_shape[0])
        return arr, transform, g, src.crs


def _process_scene(item: dict, geom: dict) -> dict:
    a = item["assets"]
    t0 = time.time()
    P = NEIGHBOUR_M
    # speed: read the 10 m bands in parallel, then the 20 m bands in parallel (2 round trips, not 5)
    f_red = BAND_POOL.submit(_read, a["red"]["href"], geom, None, P)
    f_nir = BAND_POOL.submit(_read, a["nir"]["href"], geom, None, P)
    f_grn = BAND_POOL.submit(_read, a["green"]["href"], geom, None, P)
    red_raw, tr, g, _ = f_red.result()
    shape = red_raw.shape
    f_scl = BAND_POOL.submit(_read, a["scl"]["href"], geom, shape, P)
    f_swir = BAND_POOL.submit(_read, a["swir16"]["href"], geom, shape, P) if "swir16" in a else None
    nir_raw = f_nir.result()[0]
    grn_raw = f_grn.result()[0]
    scl = f_scl.result()[0]
    swir_raw = f_swir.result()[0] if f_swir else None

    inside = geometry_mask([g], out_shape=shape, transform=tr, invert=True, all_touched=False)
    if inside.sum() == 0:  # very small field: fall back to touched pixels
        inside = geometry_mask([g], out_shape=shape, transform=tr, invert=True, all_touched=True)
    valid = inside & np.isin(scl, list(VALID_SCL)) & (red_raw > 0) & (nir_raw > 0)

    def refl(arr, key):
        s, o = _scale_offset(a[key])
        return arr.astype("float32") * s + o

    red, nir, grn = refl(red_raw, "red"), refl(nir_raw, "nir"), refl(grn_raw, "green")
    def nd(a1, a2):
        with np.errstate(divide="ignore", invalid="ignore"):
            den = a1 + a2
            v = np.where(np.abs(den) > 1e-4, (a1 - a2) / den, np.nan)
        return np.clip(v, -1, 1)

    ndvi = nd(nir, red)
    ndwi = nd(grn, nir)          # McFeeters NDWI: open water > 0
    ndmi = nd(nir, refl(swir_raw, "swir16")) if swir_raw is not None else None  # canopy moisture
    clear = np.isin(scl, list(VALID_SCL)) & (red_raw > 0) & (nir_raw > 0) & ~np.isnan(ndvi)
    valid = valid & ~np.isnan(ndvi)
    # neighbourhood ring = everything in the padded window except the field (+1 px buffer)
    near = geometry_mask([g], out_shape=shape, transform=tr, invert=True, all_touched=True)
    ring = clear & ~near & (scl == 4)  # only vegetated neighbours are a fair comparison

    n_inside = int(inside.sum())
    n_valid = int(valid.sum())
    out = {
        "date": item["properties"]["datetime"][:10],
        "scene_id": item["id"],
        "scene_cloud_pct": round(float(item["properties"].get("eo:cloud_cover", -1)), 1),
        "pixels_in_field": n_inside,
        "clear_pixels": n_valid,
        "clear_fraction": round(n_valid / n_inside, 2) if n_inside else 0,
        "read_ms": int((time.time() - t0) * 1000),
    }
    if n_valid >= max(3, 0.4 * n_inside):
        out.update(
            ndvi=round(float(np.nanmean(ndvi[valid])), 3),
            ndvi_p10=round(float(np.nanpercentile(ndvi[valid], 10)), 3),
            ndvi_p90=round(float(np.nanpercentile(ndvi[valid], 90)), 3),
            ndwi=round(float(np.nanmean(ndwi[valid])), 3),
            ndmi=round(float(np.nanmean(ndmi[valid])), 3) if ndmi is not None else None,
            usable=True,
        )
        out["neighbour_ndvi"] = round(float(np.nanmean(ndvi[ring])), 3) if ring.sum() >= 20 else None
        out["neighbour_pixels"] = int(ring.sum())
        # crop the thumbnail back to the field's bounding box
        rows, cols = np.where(inside)
        sl = (slice(rows.min(), rows.max() + 1), slice(cols.min(), cols.max() + 1))
        out["_ndvi_grid"] = np.where(valid, ndvi, np.nan)[sl]
        out["_inside"] = inside[sl]
    else:
        out["usable"] = False
        out["reason"] = "cloud/shadow over field"
    return out


def _png(ndvi: np.ndarray, inside: np.ndarray) -> str:
    """Colourise an NDVI grid (red→yellow→green) into a small base64 PNG."""
    from PIL import Image

    v = np.clip((np.nan_to_num(ndvi, nan=-1) + 0.1) / 0.9, 0, 1)
    r = np.where(v < 0.5, 1.0, 2 * (1 - v))
    gch = np.where(v < 0.5, 2 * v, 1.0)
    rgb = np.stack([r * 215 + 20, gch * 170 + 40, 50 * np.ones_like(v)], -1).astype("uint8")
    alpha = np.where(inside & ~np.isnan(ndvi), 235, np.where(inside, 90, 0)).astype("uint8")
    img = Image.fromarray(np.dstack([rgb, alpha]), "RGBA")
    scale = max(1, int(160 / max(img.size)))
    img = img.resize((img.width * scale, img.height * scale), Image.NEAREST)
    buf = io.BytesIO()
    img.save(buf, "PNG")
    return "data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()


def _interpret(series: list[dict]) -> dict:
    ok = [s for s in series if s.get("usable")]
    if not ok:
        return {"status": "no_clear_scene", "level": "UNKNOWN",
                "explanation_en": "No cloud-free Sentinel-2 view of this field in the selected period.",
                "explanation_bn": "এই সময়ে মেঘমুক্ত কোনো স্যাটেলাইট ছবি পাওয়া যায়নি। কয়েক দিন পর আবার দেখুন।"}
    latest = ok[0]
    prev = ok[1:]
    baseline = float(np.median([s["ndvi"] for s in prev[:4]])) if prev else None
    v = latest["ndvi"]
    if v < 0.2:
        band_en, band_bn = "bare soil / water / just transplanted", "খালি মাটি, পানি বা সদ্য রোপণ"
    elif v < 0.4:
        band_en, band_bn = "sparse or young crop", "পাতলা বা কচি ফসল"
    elif v < 0.6:
        band_en, band_bn = "moderate canopy", "মাঝারি সবুজ ফসল"
    else:
        band_en, band_bn = "dense healthy canopy", "ঘন ও সবুজ ফসল"
    level, flag_en, flag_bn = "LOW", [], []
    if baseline is not None:
        drop = baseline - v
        rel = drop / baseline if baseline > 0.05 else 0
        if drop >= 0.15 and rel >= 0.25:
            level = "HIGH"
        elif drop >= 0.08 and rel >= 0.12:
            level = "MEDIUM"
        if level != "LOW":
            flag_en.append(f"NDVI fell from a recent median of {baseline:.2f} to {v:.2f} ({rel*100:.0f}% drop).")
            flag_bn.append(f"ফসলের সবুজতা আগের চেয়ে কমেছে: {round(baseline*100)} থেকে {round(v*100)} (১০০-র মধ্যে), প্রায় {rel*100:.0f}% কম।")
    if latest.get("ndwi") is not None and latest["ndwi"] > 0.0:
        flag_en.append(f"NDWI {latest['ndwi']:.2f} > 0: standing water detected on the field.")
        flag_bn.append(f"NDWI {latest['ndwi']:.2f}: জমিতে পানি জমে আছে — ধানে স্বাভাবিক, অন্য ফসলে জলাবদ্ধতার ঝুঁকি।")
    if latest.get("ndmi") is not None and latest["ndmi"] < 0.0 and v > 0.3:
        flag_en.append(f"NDMI {latest['ndmi']:.2f} < 0: canopy looks moisture-stressed.")
        flag_bn.append(f"NDMI {latest['ndmi']:.2f}: পাতায় পানির ঘাটতির লক্ষণ — সেচ বিবেচনা করুন।")
    # Neighbour benchmark
    nb_latest = latest.get("neighbour_ndvi")
    nb_prev = [s["neighbour_ndvi"] for s in prev[:4] if s.get("neighbour_ndvi") is not None]
    attribution = None
    if level != "LOW" and nb_latest is not None and nb_prev:
        nb_base = float(np.median(nb_prev))
        nb_rel = (nb_base - nb_latest) / nb_base if nb_base > 0.05 else 0
        field_rel = (baseline - v) / baseline if baseline and baseline > 0.05 else 0
        if nb_rel >= 0.6 * field_rel:
            attribution = "regional"
            flag_en.append(f"Neighbouring fields fell too ({nb_base:.2f} -> {nb_latest:.2f}): likely seasonal / harvest / weather, not a problem only in your field.")
            flag_bn.append(f"আশেপাশের জমিগুলোর সবুজতাও কমেছে ({round(nb_base*100)} থেকে {round(nb_latest*100)}), তাই সম্ভবত মৌসুম, ফসল কাটা বা আবহাওয়ার কারণে।")
        else:
            attribution = "field_specific"
            flag_en.append(f"Neighbours stayed steady ({nb_base:.2f} -> {nb_latest:.2f}) while your field fell: field-specific problem (pest, disease, water or nutrients). Inspect it.")
            flag_bn.append(f"আশেপাশের জমি ঠিক আছে ({nb_base:.2f} → {nb_latest:.2f}) কিন্তু আপনার জমিতে কমেছে — পোকা, রোগ, পানি বা সারের সমস্যা হতে পারে। জমি পরিদর্শন করুন ও পাতার ছবি স্ক্যান করুন।")
    caveat_en = "A falling NDVI can also mean normal ripening or harvest. Check the field before acting."
    caveat_bn = "ফসল পাকলে বা কাটার সময়ও সবুজতা কমে যায় — কিছু করার আগে নিজে জমিতে গিয়ে দেখে নিন।"
    return {
        "status": "ok",
        "level": level,
        "latest_date": latest["date"],
        "latest_ndvi": v,
        "baseline_ndvi": round(baseline, 3) if baseline is not None else None,
        "neighbour_ndvi": nb_latest,
        "attribution": attribution,
        "canopy_class_en": band_en,
        "canopy_class_bn": band_bn,
        "flags_en": flag_en,
        "flags_bn": flag_bn,
        "caveat_en": caveat_en,
        "caveat_bn": caveat_bn,
        "rule": f"HIGH if NDVI drop >=0.15 and >=25% vs median of previous 4 clear scenes; MEDIUM if >=0.08 and >=12%. Attribution compares the same drop in vegetated pixels within {NEIGHBOUR_M:.0f} m around the field.",
    }


def analyse(geom: dict, days: int) -> dict:
    _validate_geom(geom)
    key = f"{round(_area_ha(geom),3)}:{geom['coordinates'][0][0]}:{days}:{datetime.utcnow():%Y%m%d}"
    hit = _cache.get(key)
    if hit and time.time() - hit[0] < CACHE_TTL:
        return {**hit[1], "cached": True}
    t0 = time.time()
    try:
        items = _search(geom, days)
    except Exception as e:  # noqa: BLE001
        raise HTTPException(502, f"STAC search failed: {e}")
    # cheap-first: try the clearest recent scenes first
    items = sorted(items, key=lambda f: f["properties"].get("eo:cloud_cover", 100))[: MAX_SCENES * 2]
    items = sorted(items, key=lambda f: f["properties"]["datetime"], reverse=True)[: MAX_SCENES + 4]
    results: list[dict] = []
    with ThreadPoolExecutor(max_workers=int(os.getenv("SCENE_WORKERS", "10"))) as ex:
        for r in ex.map(lambda it: _safe(it, geom), items):
            results.append(r)
    results.sort(key=lambda s: s["date"], reverse=True)
    # Haze / thin-cloud guard: SCL sometimes labels hazy pixels as clear. In a hazy
    # scene the number of pixels classed as vegetation around the field collapses.
    nps = [s["neighbour_pixels"] for s in results if s.get("usable")]
    if len(nps) >= 3:
        med = float(np.median(nps))
        for s in results:
            if s.get("usable") and med >= 50 and s["neighbour_pixels"] < 0.3 * med:
                s["usable"] = False
                s["reason"] = "haze suspected (vegetation signal around field collapsed)"
                s["suspect_ndvi"] = s.pop("ndvi", None)
    usable = [s for s in results if s.get("usable")]
    thumb = _png(usable[0]["_ndvi_grid"], usable[0]["_inside"]) if usable else None
    for s in results:
        s.pop("_ndvi_grid", None)
        s.pop("_inside", None)
    out = {
        "source": "Copernicus Sentinel-2 L2A via Element84 Earth Search (AWS Open Data)",
        "license": "Contains modified Copernicus Sentinel data, free & open (Copernicus licence)",
        "resolution_m": 10,
        "field_area_ha": round(_area_ha(geom), 2),
        "period_days": days,
        "scenes_checked": len(results),
        "series": results,
        "interpretation": _interpret(results),
        "ndvi_thumbnail_png": thumb,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "elapsed_ms": int((time.time() - t0) * 1000),
        "cached": False,
    }
    _cache[key] = (time.time(), out)
    return out


def _safe(item: dict, geom: dict) -> dict:
    try:
        return _process_scene(item, geom)
    except Exception as e:  # noqa: BLE001
        return {"date": item["properties"]["datetime"][:10], "scene_id": item["id"], "usable": False, "reason": f"read error: {type(e).__name__}"}


@app.get("/health")
def health() -> dict:
    return {"ok": True, "service": "chasha-bhai-satellite", "collection": COLLECTION, "time": datetime.now(timezone.utc).isoformat(timespec="seconds")}


@app.get("/ndvi")
def ndvi_point(lat: float, lon: float, radius_m: float = 60, days: int = 120) -> dict:
    if not (10 <= radius_m <= 400):
        raise HTTPException(400, "radius_m must be 10..400")
    if not (20 <= days <= 400):
        raise HTTPException(400, "days must be 20..400")
    return analyse(_circle(lat, lon, radius_m), days)


@app.post("/ndvi")
def ndvi_field(req: FieldRequest) -> dict:
    return analyse(req.geometry, req.days)
