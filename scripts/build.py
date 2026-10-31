"""Package only the deployable plugin, using the Python standard library."""

from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile


def main() -> None:
    root = Path(__file__).resolve().parents[1]
    source = root / "plugin" / "wpcv"
    output = root / "dist" / "wpcv.zip"
    if not (source / "wpcv.php").is_file():
        raise SystemExit("Missing plugin entry point: plugin/wpcv/wpcv.php")

    files = sorted(source.rglob("*"))
    if any(path.is_symlink() for path in files):
        raise SystemExit("Plugin source must not contain symbolic links.")

    output.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(output, "w", compression=ZIP_DEFLATED) as archive:
        for path in files:
            if path.is_file():
                archive.write(path, path.relative_to(source.parent).as_posix())
    print(f"Built {output}")


if __name__ == "__main__":
    main()
