# Reviewed CV imports

WPCV 0.3.0 provides explicit WP-CLI imports. **Normal code deployments never import or publish content.** Import commands preview by default. `--apply` always creates drafts, and existing source IDs are skipped, including entries in Trash. Apply requires a WordPress administrator selected with WP-CLI's global `--user` option.

## Harold Sikkema's 2022 CV package

The locally prepared content is under the ignored `output/import/` directory:

- `harold-sikkema-2022/review.html`: human-readable review with thumbnails, proposed categories, dates, descriptions, source text, and highlighted notes.
- `harold-sikkema-2022/review.csv`: flat review copy; `entries.json` remains the authoritative import input.
- `harold-sikkema-2022/entries.json`: 37 candidate entries with stable IDs, source pages, source text, date metadata, and image checksums.
- `harold-sikkema-2022/thumbnails/`: 37 prepared PNG files, 21 from original raster assets and 16 from source PDF crops. Equal images can reuse one WordPress media attachment.
- `harold-sikkema-cv-2022-import.zip`: portable package containing the manifest, review files, and thumbnails. Extract before running WP-CLI.

The package represents the January 7, 2022 PDF snapshot. Its 37 records comprise 22 exhibition/performance/publication entries, two degrees, and 13 teaching/professional roles. The two roles nested under nsitu.ca are separate entries. Cloud Storage retains two separate occurrences in one record, sorted by the latest occurrence; its source dates are not treated as a continuous range.

Nine records carry explicit review notes. These cover inferred categories, Cloud Storage's occurrences, a source location that needs confirmation, and the open-ended CKA/nsitu roles. Historical open-ended dates display `as of 2022`. Source domains have HTTPS prefixes inferred from the printed text; destinations have not been checked. Missing months remain unspecified. The source contact header is excluded.

Review or edit `entries.json` before import, or review and edit the resulting drafts in WordPress afterward. The importer retains each source record and its review notes in post metadata; the entry editor shows the source page, original date, notes, and extracted source text. Initial category assignments are suggestions and can be changed in WordPress.

To rebuild thumbnails and review files from the curated manifest, install/use Pillow, pdfplumber, and Poppler's `pdftoppm`, then run:

```powershell
python scripts/prepare-import.py output/import/harold-sikkema-2022/entries.json `
  --pdf 'P:/Art/2022/CV/Harold-Sikkema-CV-2022 Folder/Harold-Sikkema-CV-2022.pdf' `
  --links 'P:/Art/2022/CV/Harold-Sikkema-CV-2022 Folder/Links' `
  --output output/import/harold-sikkema-2022
```

This utility uses the reviewed page bounds and asset mappings in the manifest. It verifies the source PDF's SHA-256, preserves image aspect ratios, and prepares square thumbnails. It does not infer records from an arbitrary new PDF. The PDF and original assets are read without modification.

## Preview, import drafts, and repeat

With WPCV active and WP-CLI available in the same WordPress environment:

```sh
wp wpcv import /path/to/package/entries.json --dry-run --batch=harold-cv-2022
wp --user=YOUR_WORDPRESS_ADMIN_LOGIN wpcv import /path/to/package/entries.json --apply --batch=harold-cv-2022
```

Use `--format=json` for a machine-readable report. The report identifies every proposed/created/skipped record and its review notes. A batch is generated if you omit `--batch`; save the printed batch ID for later inspection or rollback. Choose a distinct batch name for a separate import.

Before any content writes, the entire manifest and all referenced image files are validated. Images must be local raster files inside the package with matching SHA-256 checksums. Path traversal, remote image URLs, unsupported protocols, invalid dates, and duplicate IDs in the manifest are rejected. Missing taxonomy terms are created only during apply. Images are copied into WordPress uploads; staging files can subsequently be removed.

Stable IDs are composed from `dataset_id` and each entry's `id`. Reruns skip matching entries and preserve all manual edits, publication status, and category changes. Changed source rows are reported as skipped with a note; there is no automatic update/sync mode. Retain IDs while correcting a review file. Restoring an imported entry from Trash retains its identity; rerunning the package does not recreate trashed entries.

If an individual upload fails, its newly created incomplete draft is removed, previously completed drafts remain, and the command returns a failure with its batch ID. Fix the upload issue and rerun; completed entries are skipped. Newly introduced taxonomy terms and completed media may remain after a partial failure. Simultaneous applies/rollbacks are blocked by a lock. If a process is forcibly terminated, verify that no import is still running before using `wp option delete wpcv_import_lock`.

After checking the drafts, use **CV Entries** and WordPress's normal bulk-edit tools to publish the selected records. The public shortcode shows only published entries.

## Run against CapRover

First deploy WPCV 0.3.0 through the existing workflow. These steps are separate from code deployment and reuse your SSH account. Replace the SSH values and the WordPress login below; the WordPress admin login differs from the Unix container user `www-data`.

1. On your PC, obtain the pinned WP-CLI with `bash scripts/fetch-wp-cli.sh` (Git Bash), then transfer the expanded package and PHAR with PowerShell:

   ```powershell
   $server = 'YOUR_DROPLET_IP_OR_HOSTNAME'
   $sshLogin = 'YOUR_SSH_LOGIN'
   $sshPort = 22
   $sshKey = 'C:/path/to/your/deployment-private-key'
   $destination = "${sshLogin}@${server}"
   $hostStaging = (ssh -i $sshKey -p $sshPort $destination 'mktemp -d /tmp/wpcv-import.XXXXXXXXXX').Trim()
   if ($LASTEXITCODE -ne 0 -or $hostStaging -notmatch '^/tmp/wpcv-import\.[A-Za-z0-9]+$') {
       throw 'Could not create the remote staging directory.'
   }
   scp -i $sshKey -P $sshPort -r ./output/import/harold-sikkema-2022 "${destination}:${hostStaging}/"
   scp -i $sshKey -P $sshPort ./dist/wp-cli.phar "${destination}:${hostStaging}/"
   Write-Output "Host staging directory: $hostStaging"
   ```

2. In the droplet console/SSH shell, use the printed host staging directory and your exact service name. The service must have exactly one running container on this host:

   ```bash
   HOST_STAGING=/tmp/wpcv-import.REPLACE_WITH_PRINTED_VALUE
   SERVICE=srv-captain--wordpress-wordpress
   WP_PATH=/var/www/html
   WP_RUN_USER=www-data
   CID=$(sudo docker ps --filter "label=com.docker.swarm.service.name=$SERVICE" --filter status=running --format '{{.ID}}')
   [[ "$CID" =~ ^[a-f0-9]+$ ]] || { echo 'Expected exactly one running container'; exit 1; }
   CONTAINER_STAGING=$(sudo docker exec --user "$WP_RUN_USER" "$CID" mktemp -d /tmp/wpcv-import.XXXXXXXXXX)
   [[ "$CONTAINER_STAGING" =~ ^/tmp/wpcv-import\.[A-Za-z0-9]+$ ]] || exit 1
   sudo docker cp "$HOST_STAGING/harold-sikkema-2022" "$CID:$CONTAINER_STAGING/data"
   sudo docker cp "$HOST_STAGING/wp-cli.phar" "$CID:$CONTAINER_STAGING/wp-cli.phar"
   sudo docker exec --user 0 "$CID" chown -R "$WP_RUN_USER" "$CONTAINER_STAGING"

   wp_cv() {
     sudo docker exec --user "$WP_RUN_USER" --workdir "$WP_PATH" \
       --env "WP_CLI_CACHE_DIR=$CONTAINER_STAGING/cache" "$CID" \
       php "$CONTAINER_STAGING/wp-cli.phar" --path="$WP_PATH" "$@"
   }
   wp_cv wpcv import "$CONTAINER_STAGING/data/entries.json" --dry-run --batch=harold-cv-2022
   ```

3. After reviewing the preview, create drafts:

   ```bash
   wp_cv --user=YOUR_WORDPRESS_ADMIN_LOGIN wpcv import "$CONTAINER_STAGING/data/entries.json" --apply --batch=harold-cv-2022
   ```

4. Review the drafts in WordPress. When finished with staging, remove only the validated temporary directories (this leaves uploaded media and entries intact):

   ```bash
   if [[ "$CONTAINER_STAGING" =~ ^/tmp/wpcv-import\.[A-Za-z0-9]+$ ]]; then
     sudo docker exec --user "$WP_RUN_USER" "$CID" rm -rf -- "$CONTAINER_STAGING"
   fi
   if [[ "$HOST_STAGING" =~ ^/tmp/wpcv-import\.[A-Za-z0-9]+$ ]]; then
     rm -rf -- "$HOST_STAGING"
   fi
   ```

Avoid simultaneous CapRover app replacements or code deployments during an import. WordPress uploads must be covered by your persistent storage configuration as well as the plugin directory. Do not stage packages inside the publicly served uploads folder.

## Roll back a batch

While WP-CLI is available in that environment:

```sh
wp wpcv rollback harold-cv-2022 --dry-run
wp --user=YOUR_WORDPRESS_ADMIN_LOGIN wpcv rollback harold-cv-2022 --apply
```

On CapRover, use the `wp_cv` helper above while its staging directory still exists. Rollback only moves unchanged drafts from that batch to Trash. It preserves edited entries, published entries, all media, and taxonomy terms. Trash can be restored through WordPress and remains subject to the site's normal Trash retention policy. If Trash is disabled, rollback refuses permanent deletion. This is a content-import rollback; it is independent of plugin code deployment.

## Manifest format

The minimal schema is illustrated below; the actual review package includes source bounds, image checksums, notes, and additional provenance. Optional text fields default to empty strings; optional numeric dates default to zero (unspecified). Source IDs are stable strings using lowercase letters, digits, hyphens, and underscores. Dates must be JSON integers, not strings.

```json
{
  "schema_version": 1,
  "dataset_id": "example-cv-2022",
  "source": {
    "document": "example-cv.pdf",
    "sha256": "0000000000000000000000000000000000000000000000000000000000000000"
  },
  "entries": [
    {
      "id": "example-exhibition",
      "title": "Example exhibition",
      "summary": "Solo exhibition",
      "description": "Additional source description.",
      "context": "Example Gallery",
      "location": "Toronto, Ontario",
      "url": "https://example.org",
      "year": 2021,
      "month": 5,
      "end_year": 2021,
      "end_month": 6,
      "ongoing_as_of": 0,
      "date_label": "May-Jun 2021",
      "categories": ["Exhibition"],
      "source_page": 1,
      "source_date_text": "May-Jun 2021",
      "source_text": "Original source wording",
      "review_notes": []
    }
  ]
}
```

For images add `"thumbnail": {"path": "thumbnails/example.png", "sha256": "ACTUAL_64_CHARACTER_SHA256", "alt": "Description of the source image"}` to the entry. Image paths are relative to the manifest. Thumbnails must be PNG, JPEG, GIF, or WebP, at most 20 MB and 25 megapixels. When a source has separate occurrences, keep their original label and record an optional `occurrences` array; unknown extra fields are retained in source-record metadata for future use. No remote images are fetched.
