=== Airport FID Board ===
Contributors: yourname
Tags: flights, fid, airport, timetable
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.1.43
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display flight information in a Frankfurt-style FID board using FlightLookup XML APIs.

== Description ==

Airport FID Board displays live flight information in a classic FID board style. It supports geolocation (nearest airport), custom airport selection with autocomplete, a date picker, pagination, and GitHub-based updates.

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

== Screenshots ==

1. FID board view

== Changelog ==

= 0.1.43 =
* GitHub updater support.

== Upgrade Notice ==

= 0.1.43 =
GitHub updater support and UI refinements.
