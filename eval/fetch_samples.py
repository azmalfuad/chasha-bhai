"""Download the labelled evaluation photos from Wikimedia Commons (with licence metadata)."""
import json, re, urllib.error, time, sys, urllib.parse, urllib.request, pathlib, html
UA = {"User-Agent": "ChashaBhaiEval/1.0 (https://cloudcampbd.com; student project)"}
SAMPLES = [
    ("rice_blast", "File:Rice blast Magnaporthe grisea.jpg", "rice", ["blast"]),
    ("xanthomonas_multi_panel", "File:Xanthomonas-disease.jpg", "", ["bacterial", "xanthomonas", "black rot", "blight", "unknown"]),
    ("potato_late_blight", "File:Phytophthora infestans on potato leaf.jpg", "potato", ["late blight", "phytophthora"]),
    ("tomato_late_blight", "File:Late blight on tomato leaf (7871756748).jpg", "tomato", ["late blight", "phytophthora"]),
    ("tomato_early_blight", "File:Early blight on tomato leaves (7871930010).jpg", "tomato", ["early blight", "alternaria"]),
    ("citrus_canker", "File:Citrus canker on foliage.jpg", "citrus", ["canker"]),
    ("maize_fall_armyworm", "File:Zea mays damaged by Spodoptera frugiperda (200218-0814).jpg", "maize", ["armyworm", "spodoptera", "caterpillar", "larva", "borer", "pest"]),
    ("powdery_mildew", "File:Powdery mildew 9.jpg", "", ["powdery mildew"]),
    ("cucumber_mosaic_virus", "File:Cucumber mosaic virus symptoms.jpg", "", ["mosaic", "virus"]),
    ("rice_healthy", "File:Leafs of rice plant.jpg", "rice", ["healthy"]),
    ("not_a_plant_food_plate", "File:Rice, beans and green vegetables.jpg", "", ["unknown", "not a plant", "no plant"]),
]
out = pathlib.Path(__file__).parent / "samples"; out.mkdir(exist_ok=True)
manifest = []
for sid, title, crop, expect in SAMPLES:
    q = urllib.parse.urlencode({"action": "query", "titles": title, "prop": "imageinfo", "iiprop": "url|extmetadata", "iiurlwidth": 960, "format": "json"})
    d = json.load(urllib.request.urlopen(urllib.request.Request("https://commons.wikimedia.org/w/api.php?" + q, headers=UA)))
    page = next(iter(d["query"]["pages"].values()))
    ii = page["imageinfo"][0]; md = ii.get("extmetadata", {})
    # Special:FilePath with a standard width is the rate-limit-friendly way to get a thumbnail
    url = "https://commons.wikimedia.org/wiki/Special:FilePath/" + urllib.parse.quote(title[5:]) + "?width=800"
    ext = ".png" if title.lower().endswith(".png") else ".jpg"
    fn = out / (sid + ext)
    import time
    for attempt in range(5):
        try:
            import subprocess
            r = subprocess.run(["curl", "-sfL", "-A", UA["User-Agent"], "-o", str(fn), url])
            if r.returncode == 0: break
            raise RuntimeError("curl failed")
        except RuntimeError:
            if attempt == 4: raise
            time.sleep(5 * (attempt + 1))
    time.sleep(1.5)
    strip = lambda s: re.sub("<[^>]+>", "", html.unescape(s or "")).strip()
    manifest.append({"id": sid, "file": fn.name, "commons_title": title, "page": ii.get("descriptionurl"), "crop": crop, "expect_any": expect,
                     "license": strip(md.get("LicenseShortName", {}).get("value")), "author": strip(md.get("Artist", {}).get("value"))[:120]})
    print(sid, fn.stat().st_size, manifest[-1]["license"])
(out / "manifest.json").write_text(json.dumps(manifest, indent=1, ensure_ascii=False))
