=== WPCV ===
Requires at least: 6.5
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage CV entries and display a chronological CV with category filters.

== Description ==

Adds CV Entries with titles, thumbnails, short and long descriptions, context,
month, year, URL, and location. Manage categories under CV Entries > CV Categories.

Six categories are initially provided: Exhibition, Performance, Publication,
Degree, Teaching, and Professional. These can be renamed, removed, or extended.

Use [wpcv] in a page or Shortcode block to display published entries, newest first
by their CV year and month. Use [wpcv order="ASC"] for oldest first. Undated
entries appear last. Password-protected entries are excluded. Category buttons
filter the list without a page reload. Long descriptions expand using Read more.
All entries and descriptions remain accessible without JavaScript.

== Installation ==

1. Upload wpcv.zip through Plugins > Add New Plugin > Upload Plugin, or deploy with the repository's GitHub Actions workflow.
2. Activate WPCV.
3. Add entries through CV Entries in the WordPress admin.
4. Use Excerpt for the short description and the main editor for the long description.
5. Place [wpcv] in a page and publish it.

== Changelog ==

= 0.2.0 =
* CV entry post type, editable categories, entry details, and thumbnail support.
* Chronological shortcode with category filters and expandable descriptions.

= 0.1.0 =
* Initial plugin scaffold.
