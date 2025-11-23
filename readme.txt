=== CTS Elementor Kit Generator ===
Contributors: cts
Tags: elementor, template kit, design, colors, typography
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate an Elementor Template Kit by analyzing another website's colors and typography.

== Description ==
This plugin adds a Tools page in the WordPress admin where you can enter a website URL. It fetches the page, extracts prominent colors and font families, and applies them to your active Elementor Site Kit (global colors and typography).

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Tools → Elementor Kit Generator.

== Frequently Asked Questions ==
= Does this create templates? =
This initial version updates global design tokens (colors, typography). You can expand it to generate templates/pages.

== Changelog ==
= 1.1.0 =
* Enhance color sampling by parsing linked stylesheets and inline CSS.
* Persist extra colors as Custom global colors (capped; avoids duplicates).
* Add versioning/upgrade option storage.
= 1.0.0 =
* Initial release.
