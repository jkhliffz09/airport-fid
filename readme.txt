=== Airport FID Board ===
Contributors: jkhliffz09
Tags: flights, fid, airport, timetable, departures
Requires at least: 5.8
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 0.2.40
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Classic airport flight information display board with live data, geolocation, date picker, and GitHub updates.

== Description ==

Airport FID Board displays live flight information in a classic Frankfurt-style FID board. It supports:

- Geolocation (nearest airport lookup)
- Airport autocomplete
- Date picker + pagination
- Airline logo display
- GitHub-based updates

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Airport FID Board in the admin menu and add your FlightLookup API key.

== Usage ==

Use the shortcode:

`[fid_board]`

Attributes:

- `airport` (default from settings)
- `use_geolocation` (`1` or `0`)
- `show_destination` (`1` or `0`)
- `limit` (max flights)

== Settings ==

- **FlightLookup API Key**
- **Default Airport (IATA)**
- **Use Geolocation by Default**
- **Max Destinations / Max Flights / Cache TTL**
- **GitHub Repo URL** (for updates)
- **GitHub Token** (optional, for private repos)

== Screenshots ==

1. FID board view
2. Expanded flight details
3. Settings page

== Frequently Asked Questions ==

= How do updates work? =

Updates use GitHub releases (not prereleases). Create a release and WordPress will show an update.

= Where does the data come from? =

FlightLookup XML APIs.

== Changelog ==

= 0.2.40 =
* Added browser-specific screenshots to the location help popup for Chrome, Safari, Edge, and Firefox.
* Opens the location help popup when geolocation is blocked for the site.

= 0.2.39 =
* Moved the airport helper text directly below the airport controls and above the date section.
* Improved the Allow Location retry flow and helper messaging after geolocation denial.

= 0.2.38 =
* Moved the airport/location helper text below the controls and only show the result status after flights load.
* Hid the Load more button until results exist and added an Allow Location retry action after geolocation denial.
* Made the nearby-airport picker scrollable for long airport lists.

= 0.2.37 =
* Fixed nearby-airport popup styling in light mode for readable text and proper contrast.

= 0.2.36 =
* Removed automatic frontend loading and default airport prefills.
* Added nearby-airport picker modal for Use My Location when multiple airports are returned.
* Updated the empty state to prompt users to select an airport or use location first.

= 0.2.35 =
* Made Hub the primary tab when enabled, otherwise Analytics becomes the default tab.
* Moved General Settings to the last tab.
* Removed Top Source summaries from Analytics and kept Save Settings only inside General Settings.

= 0.2.34 =
* Switched Hub filtering to client-side dropdowns and date picker above the data table.
* Added Hub table pagination with 25 items per page.

= 0.2.33 =
* Added Hub filters for Site, Airport, and Date with a date picker.
* Kept the Hub tab active after applying filters.

= 0.2.32 =
* Fixed analytics schema upgrades on existing installs so hub site columns are added automatically.
* Restored backfill when older cache-derived rows are missing site metadata required for Hub analytics.
* Updated backfill to refresh existing cache-backfill rows and forward repaired entries to the hub.

= 0.2.31 =
* Split local Analytics from Hub analytics so each site shows its own data by default.
* Added a dedicated Hub tab that appears only when hub mode is enabled.

= 0.2.30 =
* Added hub-and-sender analytics over WP REST for combining search data from multiple sites into one dashboard.
* Added site-aware analytics fields and dashboard reporting by site label.
* Switched the admin menu icon to an inline SVG data URI to avoid stale cached icon files.

= 0.2.29 =
* Fixed the oversized admin sidebar icon with a proper WordPress-sized SVG.
* Added `Tested up to: 6.9.1` to plugin metadata for current WordPress compatibility signaling.

= 0.2.28 =
* Moved Airport FID Board out of WordPress Settings into its own top-level admin menu.
* Added dedicated admin tabs for Analytics and Cached Items.
* Added a custom sidebar menu icon for the plugin admin page.

= 0.2.27 =
* Added Analytics search logging tied to cache rows through `cache_id` instead of duplicating payload data.
* Added Analytics dashboard cards, top-airport/source summaries, and recent search history in settings.
* Added one-time cache backfill to include existing cached requests in Analytics, then hide the backfill button after success.

= 0.2.26 =
* Fixed repeated flip animation during batch route fetch by animating only once after final sorted results are ready.
* Improved geolocation enablement parsing on frontend (`1/true/yes`) with fallback to localized config values.
* Added featured-image filename revisioning to reduce stale image cache issues after regeneration.

= 0.2.25 =
* Removed persistent completed progress block after queue completion.
* Added dismissible completion summary with an OK button to clear last-run status.

= 0.2.24 =
* Added airport-page generation progress bar with percentage and counters.
* Added current-airport and last-action status details during queue runs.
* Added settings-page auto-refresh and queue fallback chunk processing to keep progress moving when WP-Cron is delayed.

= 0.2.23 =
* Fixed airport page queue getting stuck at 0 progress by processing the first airport immediately on manual runs.
* Added admin-side queue worker recovery when a running queue has no scheduled worker.

= 0.2.22 =
* Moved airport page generation to a background queue with batch worker processing to reduce timeout risk.
* Added generation queue status notices in admin (queued, progress, last run summary).
* Tuned featured-image headline typography for stronger left-side emphasis.

= 0.2.21 =
* Updated airport page generation to use the latest cached date that actually has flights.
* Improved featured-image headline prominence for "FLIGHT SCHEDULE & DEPARTURE BOARD".

= 0.2.20 =
* Added animated loading dialog for Generate/Update Airport Pages.
* Added rotating real-time status messages during airport page generation, including AI about-section progress.

= 0.2.19 =
* Added AI-generated "About Airport Flight Schedules" section for generated pages.
* Added provider switch for OpenAI or Claude with separate API key/model settings.
* Added weekly caching for generated AI about content with safe fallback text.
* Fixed featured image text rendering by removing icon-font fallback and adding robust text draw fallback.

= 0.2.18 =
* Fixed generated featured image text rendering when TTF/FreeType fonts are unavailable.
* Added `Settings` link in plugin row actions (`Settings | Deactivate`).

= 0.2.17 =
* Added dynamic featured image generation for generated airport pages.
* Added automatic featured image attach/update during airport page sync.
* Replaced generated page live board shortcode block with CTA button block.

= 0.2.16 =
* Added weekly airport page generator from cached request items.
* Added per-airport page create/update logic (e.g. `JFK Airport Flight Schedules`).
* Added admin action to manually generate/update airport pages.
* Added ATL-style generated airport page template CSS.

= 0.2.15 =
* Added CSV export/import controls for cache item inline editing.

= 0.2.14 =
* Moved Cache Items into a dedicated section below settings.
* Added inline JSON editing and per-item cache update in admin.

= 0.2.13 =
* Added Cache Items admin tab to view cached request records.

= 0.2.12 =
* Defer sorting until all batch results are fetched to avoid repeated flip animation.

= 0.2.11 =
* Redesigned admin settings into tabbed General, Typography, and Layout panels.
* Added configurable typography, colors, sizing, theme mode, and animation controls.

= 0.2.10 =
* Added day indicator badge to main row arrival time.

= 0.2.09 =
* Added cache refresh day setting with Wednesday fallback.

= 0.2.08 =
* Arrival day indicator now renders as superscript badge.

= 0.2.07 =
* Increased time column width and highlighted status text.

= 0.2.06 =
* Added sort order control and fixed time wrapping.

= 0.2.05 =
* Changelog now appends previous entries.

= 0.2.04 =
* Main row times now use 12-hour format.

= 0.2.03 =
* Removed debug output helpers from update checker.

= 0.2.02 =
* Fixed day indicator parsing for NEXT DAY labels.

= 0.2.00 =
* Improved day indicator visibility in expanded timeline.

== Upgrade Notice ==

= 0.2.40 =
Adds screenshot-based browser instructions to the location help popup when geolocation is blocked.

= 0.2.39 =
Moves the helper text to the airport section and improves the Allow Location retry flow.

= 0.2.38 =
Refines the manual search flow, hides Load more until results exist, and improves long nearby-airport lists.

= 0.2.37 =
Fixes the nearby-airport modal appearance in light mode.

= 0.2.36 =
Changes the frontend flow to manual loading and adds a nearby-airport picker for geolocation.

= 0.2.35 =
Improves the admin tab order and streamlines the Analytics view.

= 0.2.34 =
Adds client-side Hub filtering and 25-row pagination for the Hub results table.

= 0.2.33 =
Adds Hub filters for site, airport, and date in the admin dashboard.

= 0.2.32 =
Fixes hub analytics migration and allows backfill to repair older rows for hub reporting.

= 0.2.31 =
Separates local Analytics from cross-site Hub reporting in the admin dashboard.

= 0.2.30 =
Adds multi-site analytics hub support and fixes stale admin icon caching.

= 0.2.29 =
Fixes the admin menu icon sizing and updates plugin metadata for WordPress 6.9.1.

= 0.2.28 =
Moves the plugin to its own admin menu and adds dedicated Analytics and Cached Items tabs.

= 0.2.27 =
Adds Analytics search logging plus a one-time backfill tool for existing cache records.

= 0.2.26 =
Batch fetch animation now triggers once at final render, plus improved geolocation parsing and featured-image cache busting.

= 0.2.25 =
Completed queue progress is now dismissible via an OK button and no longer persists indefinitely.

= 0.2.24 =
Airport page generation now includes a live progress bar and stronger queue fallback processing.

= 0.2.23 =
Queue progress reliability improvements for manual airport page generation.

= 0.2.22 =
Background queued airport page generation and stronger featured-image headline styling.

= 0.2.21 =
Airport page generation now prefers latest non-empty cache date and has larger featured-image headline text.

= 0.2.20 =
Animated generation dialog with real-time progress text for airport page sync.

= 0.2.19 =
AI-generated About section support (OpenAI/Claude) and featured-image text fallback improvements.

= 0.2.18 =
Featured image text rendering fallback and plugin row Settings link.

= 0.2.17 =
Dynamic featured image generation for generated airport schedule pages.

= 0.2.16 =
Auto-generate and weekly update airport schedule pages from cached data.

= 0.2.15 =
Cache item CSV import/export controls.

= 0.2.14 =
Cache items moved below settings with inline JSON editing.

= 0.2.13 =
Cache items admin section added.

= 0.2.12 =
Batch sorting after fetch completes.

= 0.2.11 =
Tabbed admin settings with typography/layout controls.

= 0.2.10 =
Main row day indicator badge.

= 0.2.09 =
Cache refresh day fallback.

= 0.2.08 =
Arrival day badge update.

= 0.2.07 =
Time column width and status color update.

= 0.2.06 =
Sort order control and time wrapping fix.

= 0.2.05 =
Changelog now appends previous entries.

= 0.2.04 =
Main row time format update.
