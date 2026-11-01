"""Prepare a reviewed CV manifest, raster thumbnails, and human-readable review.

Requires Pillow and pdfplumber, plus pdftoppm on PATH (or --pdftoppm).
This does not guess record boundaries or classifications: reviewed records carry
explicit source page/bounds and original-asset mappings. No WordPress writes.
"""

import argparse
import csv
import hashlib
import html
import json
from pathlib import Path
import re
import subprocess
import tempfile
from zipfile import ZipFile, ZIP_DEFLATED

import pdfplumber
from PIL import Image, ImageOps


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def csv_text(value: object) -> str:
    text = str(value)
    return "'" + text if text.startswith(("=", "+", "-", "@", "\t", "\r")) else text


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("--pdf", required=True, type=Path)
    parser.add_argument("--links", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--pdftoppm", default="pdftoppm")
    args = parser.parse_args()
    data = json.loads(args.manifest.read_text(encoding="utf-8-sig"))
    if data.get("schema_version") != 1 or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,99}", data.get("dataset_id", "")):
        raise SystemExit("Invalid manifest schema or dataset ID.")
    if data["source"]["sha256"] != digest(args.pdf):
        raise SystemExit("Source PDF checksum differs from the reviewed manifest.")
    for record in data["entries"]:
        record["ongoing"] = record.get("ongoing", bool(record.get("ongoing_as_of")))
        record.pop("ongoing_as_of", None)
        record["review_notes"] = [note for note in record["review_notes"] if not note.startswith("Open-ended in the January 2022 source. This does not establish")]
        if record["ongoing"] and re.search(r"[-–—]\s*$", record["date_label"]):
            record["date_label"] += "present"

    output = args.output.resolve()
    thumbnails = output / "thumbnails"
    thumbnails.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="wpcv-pdf-") as temp, pdfplumber.open(args.pdf) as pdf:
        rendered = {}
        seen = set()
        for record in data["entries"]:
            record_id = record["id"]
            if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,99}", record_id) or record_id in seen:
                raise SystemExit(f"Duplicate or unsafe record ID: {record_id}")
            seen.add(record_id)
            page_number = record["source_page"]
            page = pdf.pages[page_number - 1]
            record["source_text"] = page.crop(tuple(record["source_bbox"])).extract_text() or ""
            image_spec = record["thumbnail"]
            if image_spec.get("original_asset"):
                original = (args.links / image_spec["original_asset"]).resolve()
                if original.parent != args.links.resolve():
                    raise SystemExit("Original asset must be directly inside the Links folder.")
                image = ImageOps.exif_transpose(Image.open(original)).convert("RGBA")
                image_spec["method"] = "original-asset"
            else:
                if page_number not in rendered:
                    prefix = Path(temp) / f"page-{page_number}"
                    subprocess.run([args.pdftoppm, "-f", str(page_number), "-l", str(page_number), "-singlefile", "-r", "288", "-png", str(args.pdf), str(prefix)], check=True, capture_output=True)
                    rendered[page_number] = Image.open(prefix.with_suffix(".png")).convert("RGBA")
                image = rendered[page_number].crop(tuple(round(coordinate * 4) for coordinate in image_spec["pdf_bbox"]))
                image_spec["method"] = "source-pdf-crop"
            # Composite transparency and contain artwork/logos on a square canvas
            # so the plugin's square thumbnail display preserves their contents.
            background = Image.new("RGBA", image.size, "white")
            background.alpha_composite(image)
            image = ImageOps.contain(background.convert("RGB"), (488, 488), Image.Resampling.LANCZOS)
            canvas = Image.new("RGB", (512, 512), "white")
            canvas.paste(image, ((512 - image.width) // 2, (512 - image.height) // 2))
            target = thumbnails / f"{record_id}.png"
            canvas.save(target, optimize=True)
            image_spec["path"] = f"thumbnails/{record_id}.png"
            image_spec["sha256"] = digest(target)

    (output / "entries.json").write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    columns = ["id", "title", "categories", "source_date_text", "year", "month", "end_year", "end_month", "ongoing", "date_label", "summary", "description", "context", "location", "url", "source_page", "review_notes", "thumbnail"]
    with (output / "review.csv").open("w", newline="", encoding="utf-8-sig") as stream:
        writer = csv.DictWriter(stream, fieldnames=columns)
        writer.writeheader()
        for record in data["entries"]:
            row = {key: record.get(key, "") for key in columns}
            row["categories"] = " / ".join(record["categories"])
            row["review_notes"] = " | ".join(record["review_notes"])
            row["thumbnail"] = record["thumbnail"]["path"]
            writer.writerow({key: csv_text(value) for key, value in row.items()})

    esc = lambda value: html.escape(str(value), quote=True)
    sections = []
    for index, record in enumerate(data["entries"], 1):
        notes = "".join(f"<li>{esc(note)}</li>" for note in record["review_notes"])
        dates = f"Start: {record['year'] or 'unknown'} / {record['month'] or 'unspecified'}; end: {record['end_year'] or 'none'} / {record['end_month'] or 'unspecified'}"
        if record["ongoing"]:
            dates += "; ongoing to the present"
        description = f"<p>{esc(record['description'])}</p>" if record["description"] else ""
        link = f"<a href=\"{esc(record['url'])}\">{esc(record['url'])}</a>" if record["url"].startswith(("https://", "http://")) else "No link in source"
        sections.append(f"""<article><img src="{esc(record['thumbnail']['path'])}" alt="{esc(record['thumbnail']['alt'])}"><div>
        <small>{index:02d} · Page {record['source_page']} · {esc(' / '.join(record['categories']))}</small>
        <h2>{esc(record['title'])}</h2><p><strong>Source date:</strong> {esc(record['source_date_text'])}</p>
        <p>{esc(record['summary'])}</p>{description}<p>{esc(record['context'])}<br>{esc(record['location'])}<br>{link}</p>
        <p class="meta">{esc(dates)}<br>Thumbnail: {esc(record['thumbnail']['method'])}<br>ID: {esc(record['id'])}</p>
        {('<ul class="notes">' + notes + '</ul>') if notes else ''}
        <details><summary>Extracted source text</summary><pre>{esc(record['source_text'])}</pre></details></div></article>""")
    flagged = sum(bool(record["review_notes"]) for record in data["entries"])
    markup = """<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CV import review</title>
    <style>body{font:16px/1.5 system-ui,sans-serif;color:#202923;background:#f4f6f2;margin:0}main{max-width:1080px;padding:2rem;margin:auto}header{padding:1.5rem;background:#e2ebdf;border-radius:8px;margin-bottom:1rem}h1{margin:0}h2{margin:.3rem 0;font-size:1.25rem}p{margin:.5rem 0}article{display:grid;grid-template-columns:120px minmax(0,1fr);gap:1.5rem;background:white;padding:1.5rem;border:1px solid #d6dfd3;border-radius:8px;margin:1rem 0}img{width:120px;height:120px;object-fit:contain;border:1px solid #eee}small,.meta{color:#51604d}.meta{font-size:.85rem}.notes{background:#fff4cc;padding:1rem 1rem 1rem 2rem}a,pre{overflow-wrap:anywhere}pre{white-space:pre-wrap;font:inherit;color:#51604d}@media(max-width:600px){main{padding:1rem}article{grid-template-columns:1fr}img{width:90px;height:90px}}</style><main>"""
    markup += f"<header><h1>CV import review</h1><p>{len(data['entries'])} candidate entries · {flagged} entries with review notes · drafts only</p><p>Source: {esc(data['source']['document'])} · snapshot {esc(data['source']['as_of'])}</p><p>Links use HTTPS inferred from the printed addresses; destinations have not been verified. Categories are editorial suggestions from the source wording. Missing months remain unspecified. Source extraction may contain font-encoding artifacts; the entry fields below preserve the visually checked wording.</p></header>"
    markup += "".join(sections) + "</main></html>"
    (output / "review.html").write_text(markup, encoding="utf-8")

    archive = output.parent / f"{data['dataset_id']}-import.zip"
    files = [output / "entries.json", output / "review.csv", output / "review.html"] + [output / record["thumbnail"]["path"] for record in data["entries"]]
    with ZipFile(archive, "w", ZIP_DEFLATED) as bundle:
        for path in files:
            bundle.write(path, path.relative_to(output).as_posix())
    print(json.dumps({"entries": len(data["entries"]), "flagged": flagged, "original_assets": sum(r["thumbnail"]["method"] == "original-asset" for r in data["entries"]), "pdf_crops": sum(r["thumbnail"]["method"] == "source-pdf-crop" for r in data["entries"]), "output": str(output), "zip": str(archive)}))


if __name__ == "__main__":
    main()
