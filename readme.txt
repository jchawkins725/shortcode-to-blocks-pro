=== Shortcode to Blocks Pro ===
Contributors: jchawkins725
Tags: gutenberg, wpbakery, shortcode, converter, migration
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for Shortcode to Blocks — batch conversion, 17 advanced converters, logging, tools, and license key activation.

== Description ==

**Shortcode to Blocks Pro** is a premium add-on for the free [Shortcode to Blocks](https://wordpress.org/plugins/shortcode-to-blocks/) plugin. It extends the free version with advanced WPBakery shortcode converters, batch processing, full conversion logging, and maintenance tools.

**Requires**: [Shortcode to Blocks](https://wordpress.org/plugins/shortcode-to-blocks/) (free) must be installed and activated.

= What Pro Adds =

**17 Additional Shortcode Converters**

The free plugin handles the core layout and content shortcodes. Pro adds converters for:

* **Call-to-Action** — `vc_cta` → Group block with heading, content, and button
* **Toggle / Details** — `vc_toggle` → Details block
* **Video** — `vc_video` → Embed block (YouTube, Vimeo, or HTML)
* **Google Maps** — `vc_gmaps` → Embed block
* **Raw JavaScript** — `vc_raw_js` → Custom HTML block
* **Icon** — `vc_icon` → Custom HTML block with FontAwesome / icon classes
* **Tabs** — `vc_tta_tabs`, `vc_tabs` → Heading + content structures
* **Tours** — `vc_tta_tour`, `vc_tour` → Heading + content structures
* **Accordions** — `vc_tta_accordion`, `vc_accordion` → Heading + content structures
* **Message / Alert** — `vc_message` → Styled Group block
* **Gallery** — `vc_gallery` → Gallery block with columns, sizing, and link options
* **Media Grid** — `vc_media_grid` → Gallery block
* **Post Grid** — `vc_basic_grid` → Preserved with migration note
* **Masonry Grid** — `vc_masonry_media_grid` → Preserved with migration note

Combined with the free plugin's 11 converters, Pro supports **28 WPBakery shortcodes** total.

**Batch Conversion & Revert**

* Convert hundreds of posts at once — filter by post type, parent, or date range
* Dry-run preview to see what would change before committing
* Batch revert to undo an entire batch in one click
* Bulk action on the Posts / Pages list table

**Logging & Auditing**

* Full history of every conversion, revert, batch, and error
* Filterable, sortable log viewer in the admin
* CSV export for external auditing or reporting

**Tools & Maintenance**

* WPBakery content detection scan across all post types
* Purge old backups by age threshold or all at once
* Optional daily WP-Cron auto-purge for backups older than 1 year
* Purge logs by age or clear all
* Converted Posts list with filtering by type, batch ID, or search

**License Key Activation**

* Activate your license key in Settings to receive updates and support

= Why Upgrade? =

The free plugin is great for small sites with a handful of pages. If you have dozens or hundreds of WPBakery posts, need advanced shortcode support, or want a full audit trail, Pro saves hours of manual work.

== Installation ==

1. Install and activate the free [Shortcode to Blocks](https://wordpress.org/plugins/shortcode-to-blocks/) plugin first.
2. Upload the `shortcode-to-blocks-pro` folder to `/wp-content/plugins/`, or install via the Plugins screen.
3. Activate **Shortcode to Blocks Pro**.
4. Go to **Shortcode → Blocks → Settings** and enter your license key.
5. The Pro tabs (Convert, Revert, Tools, Logs, Converted Posts) will appear in the plugin navigation.

== Frequently Asked Questions ==

= Do I need the free plugin? =
Yes. The Pro add-on requires [Shortcode to Blocks](https://wordpress.org/plugins/shortcode-to-blocks/) (free) to be installed and active. Pro extends the free plugin — it does not replace it.

= Does this work with other page builders? =
No. Both the free and Pro plugins are designed exclusively for WPBakery Page Builder (Visual Composer) shortcodes.

= Does Pro convert all WPBakery elements? =
Pro supports 28 shortcodes total (11 from the free plugin + 17 Pro-only). Any remaining unsupported shortcodes are preserved inside a Gutenberg Shortcode block, so no content is lost.

= Can I revert after a batch conversion? =
Yes. Every conversion stores a backup. You can revert individual posts from the editor, or revert an entire batch from the Revert page.

= Is batch conversion safe for production? =
Always test on staging first. Use the **dry-run** option to preview changes before committing. The plugin creates automatic backups of every converted post.

= How does tab/tour/accordion conversion work? =
Each tab or section becomes an H3 heading followed by its content. This creates clean document structure with better SEO and accessibility while preserving all nested content (forms, images, shortcodes, etc.).

= What if I deactivate the Pro plugin? =
The free plugin continues to work for single-post conversion with its 11 basic converters. Already-converted content is unaffected. Pro features (batch tools, logs, advanced converters) are simply no longer available until Pro is reactivated.

== Screenshots ==

1. Convert / Revert buttons in the editor sidebar panel
2. Batch conversion page with dry-run and filtering
3. Batch revert page
4. Tools page with backup and log management
5. Logs page with conversion history and CSV export
6. Converted Posts list with filtering
7. Settings page with license key activation

== Changelog ==

= 1.0.0 =
* Initial release as a Pro add-on for Shortcode to Blocks (free)
* 17 advanced WPBakery shortcode converters:
  - CTA, toggle, video, Google Maps, raw JS, icon
  - Tabs, tours, accordions (classic and TTA)
  - Message/alert, gallery, media grid, basic grid, masonry grid
* Batch conversion with dry-run preview and post-type filtering
* Batch revert by batch ID
* Bulk action on Posts / Pages list tables
* Full conversion logging with CSV export
* Tools: backup purge, log purge, WPBakery content detection scan
* Converted Posts admin page
* License key activation
* Auto-purge WP-Cron for backups older than 1 year

== Upgrade Notice ==

= 1.0.0 =
First release. Requires the free Shortcode to Blocks plugin. Test on staging before converting production content.