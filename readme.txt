=== Airport FID Board ===
Contributors: jkhliffz09
Tags: flights, fid, airport, timetable, departures
Requires at least: 5.8
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 0.1.50
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

= 0.1.50 =
* Status line now shows full airport name when available.
* Departure/arrival display tweaks and column sizing adjustments.
* Word-wrapping improvements for airport names.

== Upgrade Notice ==

= 0.1.50 =
UI and layout refinements.
