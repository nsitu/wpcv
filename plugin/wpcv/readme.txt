=== WPCV ===
Requires at least: 6.5
Requires PHP: 8.2
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage CV entries and display a chronological CV with category filters.

== Description ==

Adds CV Entries with titles, thumbnails, short and long descriptions, context,
month, year, URL, and location. Manage categories under CV Entries > CV Categories.

Six categories are initially provided: Exhibition, Performance, Publication,
Degree, Teaching, and Professional. These can be renamed, removed, or extended.

Use [wpcv] in a page or Shortcode block to display published entries, newest first
by their latest CV endpoint. Use [wpcv order="ASC"] for oldest first. Undated
entries appear last. Password-protected entries are excluded. Category buttons
filter the list without a page reload. Long descriptions expand using Read more.
All entries and descriptions remain accessible without JavaScript.

Entry details support optional end dates, date display overrides for separate
occurrences, and a simple Ongoing checkbox. Open-ended roles continue to the
present and sort using the current month/year, including older imports.

Dashboard: open CV Entries > Import, upload JSON or a ZIP containing entries.json
and thumbnails, and review the preview. Click Import as drafts to create entries
in small batches. Results link to the imported drafts for review and publication.
JSON with local thumbnail references needs the ZIP package or the explicit
text-only option. Previews are private to the uploading administrator and expire
after one hour. Repeated uploads skip source IDs that are already imported.

WP-CLI is also available: wp wpcv import /path/to/entries.json --dry-run previews a reviewed package.
Add --apply --user=YOUR_ADMIN_LOGIN --batch=YOUR_BATCH to create drafts. Imports
validate the entire package, preserve source data, reuse media by checksum, and
skip source IDs already imported. Existing entries are never overwritten.

wp wpcv rollback YOUR_BATCH previews rollback. Add --apply --user=YOUR_ADMIN_LOGIN
to move unchanged drafts to Trash. Published/edited entries and media are kept.

== Installation ==

1. Upload wpcv.zip through Plugins > Add New Plugin > Upload Plugin, or deploy with the repository's GitHub Actions workflow.
2. Activate WPCV.
3. Add entries through CV Entries in the WordPress admin.
4. Use Excerpt for the short description and the main editor for the long description.
5. Place [wpcv] in a page and publish it.

== Changelog ==

= 0.4.1 =
* Replace the ongoing-as-of year with a simple Ongoing checkbox.
* Treat open-ended roles as current and remove source-year qualifiers.
* Preserve compatibility with older import packages and existing entries.

= 0.4.0 =
* Dashboard import UI with JSON/ZIP uploads, private thumbnail previews, review
  notes, bounded draft imports, resumable progress, and source-ID deduplication.
* Private temporary staging, nonce and permission checks, ZIP inspection, and
  automatic staging cleanup after completion/discard or scheduled expiration.

= 0.3.0 =
* Date ranges, source date labels, and historical ongoing-as-of dates.
* Preview-first JSON importer, checksum-verified local images, source tracking,
  draft-only creation, duplicate prevention, and conservative batch rollback.

= 0.2.0 =
* CV entry post type, editable categories, entry details, and thumbnail support.
* Chronological shortcode with category filters and expandable descriptions.

= 0.1.0 =
* Initial plugin scaffold.
