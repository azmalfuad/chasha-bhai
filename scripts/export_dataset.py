"""Chasha Bhai — build an anonymous labelled dataset from the app store.

Reads the SQLite store written by the PHP API (data/runtime/chasha.sqlite, or the
JSON-lines fallback under data/runtime/store/) and writes one row per diagnosis
joined with the farmer's verdict, as CSV and/or Apache Parquet.

No photo, no IP and no location is exported (the grid cell is dropped on purpose).
Use it to grow the evaluation set in eval/ from real farmer feedback.

    python scripts/export_dataset.py --out eval/results/labelled_dataset --formats csv,parquet [--only-labelled]

Parquet needs pyarrow (pip install pyarrow). CSV needs only the standard library.
"""
from __future__ import annotations

import argparse
import csv
import json
import sqlite3
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
COLUMNS = ["id", "created_at", "crop", "disease", "category", "photo_conf", "final_conf", "agreement",
           "escalated", "weather_used", "satellite_used", "verdict", "correct_label"]


def read_rows(data_dir: Path, only_labelled: bool) -> list[dict]:
    db = data_dir / "chasha.sqlite"
    if db.is_file():
        con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
        con.row_factory = sqlite3.Row
        sql = ("SELECT d.id, d.created_at, d.crop, d.disease, d.category, d.photo_conf, d.final_conf, d.agreement, d.escalated, "
               "d.weather_used, d.satellite_used, f.verdict, f.correct_label FROM diagnoses d LEFT JOIN feedback f ON f.diagnosis_id = d.id"
               + (" WHERE f.verdict IS NOT NULL" if only_labelled else "") + " ORDER BY d.created_at")
        rows = [dict(r) for r in con.execute(sql)]
        con.close()
        return rows
    # JSON-lines fallback (hosts without pdo_sqlite)
    def jl(name: str) -> list[dict]:
        p = data_dir / "store" / f"{name}.jsonl"
        return [json.loads(l) for l in p.read_text(encoding="utf-8").splitlines() if l.strip()] if p.is_file() else []
    fb = {f["diagnosis_id"]: f for f in jl("feedback")}
    out = []
    for d in jl("diagnoses"):
        f = fb.get(d["id"])
        if only_labelled and not f:
            continue
        out.append({c: d.get(c) for c in COLUMNS[:11]} | {"verdict": f and f.get("verdict"), "correct_label": f and f.get("correct_label")})
    return out


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--data-dir", default=str(ROOT / "data" / "runtime"))
    ap.add_argument("--out", default=str(ROOT / "eval" / "results" / "labelled_dataset"))
    ap.add_argument("--formats", default="csv,parquet")
    ap.add_argument("--only-labelled", action="store_true")
    a = ap.parse_args()
    rows = read_rows(Path(a.data_dir), a.only_labelled)
    out = Path(a.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    fmts = {f.strip() for f in a.formats.split(",") if f.strip()}
    if "csv" in fmts:
        with open(out.with_suffix(".csv"), "w", newline="", encoding="utf-8") as fh:
            w = csv.DictWriter(fh, fieldnames=COLUMNS)
            w.writeheader()
            w.writerows({c: r.get(c) for c in COLUMNS} for r in rows)
        print(f"wrote {out.with_suffix('.csv')} ({len(rows)} rows)")
    if "parquet" in fmts:
        try:
            import pyarrow as pa
            import pyarrow.parquet as pq
        except ImportError:
            raise SystemExit("Parquet needs pyarrow: pip install pyarrow")
        schema = pa.schema([("id", pa.string()), ("created_at", pa.string()), ("crop", pa.string()), ("disease", pa.string()),
                            ("category", pa.string()), ("photo_conf", pa.float64()), ("final_conf", pa.float64()), ("agreement", pa.string()),
                            ("escalated", pa.int8()), ("weather_used", pa.int8()), ("satellite_used", pa.int8()),
                            ("verdict", pa.string()), ("correct_label", pa.string())])
        table = pa.Table.from_pylist([{c: r.get(c) for c in COLUMNS} for r in rows], schema=schema)
        pq.write_table(table, out.with_suffix(".parquet"), compression="zstd")
        print(f"wrote {out.with_suffix('.parquet')} ({table.num_rows} rows)")


if __name__ == "__main__":
    main()
