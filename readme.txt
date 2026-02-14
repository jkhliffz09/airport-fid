=== Airport FID Board ===
Contributors: jkhliffz09
Tags: flights, fid, airport, timetable, departures
Requires at least: 5.8
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 0.2.08
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
3. Go to Settings → Airport FID Board and add your FlightLookup API key.

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
