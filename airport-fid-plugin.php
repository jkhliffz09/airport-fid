<?php
/**
 * Plugin Name: Airport FID Board
 * Description: Display flight information in a FID-style table using FlightLookup XML APIs.
 * Version: 0.2.35
 * Author: khliffz
 * Requires at least: 5.8
 * Tested up to: 6.9.1
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

const AIRPORT_FID_OPTION_KEY = 'airport_fid_settings';
const AIRPORT_FID_VERSION = '0.2.35';
const AIRPORT_FID_CACHE_TABLE = 'airport_fid_cache';
const AIRPORT_FID_SEARCH_LOG_TABLE = 'airport_fid_search_log';
const AIRPORT_FID_PAGE_META_FLAG = '_airport_fid_generated_page';
const AIRPORT_FID_PAGE_META_AIRPORT = '_airport_fid_airport_code';
const AIRPORT_FID_PAGE_SYNC_HOOK = 'airport_fid_generate_airport_pages_event';
const AIRPORT_FID_PAGE_META_FEATURED = '_airport_fid_featured_attachment_id';
const AIRPORT_FID_PAGE_WORKER_HOOK = 'airport_fid_generate_airport_pages_worker_event';
const AIRPORT_FID_PAGE_QUEUE_OPTION = 'airport_fid_page_generation_queue';
const AIRPORT_FID_PAGE_LAST_RESULT_OPTION = 'airport_fid_page_generation_last_result';
const AIRPORT_FID_SEARCH_BACKFILL_DONE_OPTION = 'airport_fid_search_backfill_done';

function airport_fid_install() {
    global $wpdb;
    $table = $wpdb->prefix . AIRPORT_FID_CACHE_TABLE;
    $search_table = $wpdb->prefix . AIRPORT_FID_SEARCH_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        airport char(3) NOT NULL,
        flight_date char(8) NOT NULL,
        sort varchar(20) NOT NULL,
        payload longtext NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY airport_date_sort (airport, flight_date, sort),
        KEY updated_at (updated_at)
    ) {$charset};

    CREATE TABLE {$search_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        cache_id bigint(20) unsigned DEFAULT NULL,
        airport char(3) NOT NULL,
        flight_date char(8) NOT NULL,
        sort varchar(20) NOT NULL,
        source varchar(20) NOT NULL,
        raw_input varchar(120) DEFAULT '',
        selected_airport varchar(120) DEFAULT '',
        site_url varchar(255) DEFAULT '',
        site_label varchar(120) DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY cache_id (cache_id),
        KEY airport_date (airport, flight_date),
        KEY source (source),
        KEY site_url (site_url),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function airport_fid_activation_setup() {
    airport_fid_install();
    airport_fid_schedule_airport_pages_sync(true);
}
register_activation_hook(__FILE__, 'airport_fid_activation_setup');

function airport_fid_deactivation_cleanup() {
    $timestamp = wp_next_scheduled(AIRPORT_FID_PAGE_SYNC_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, AIRPORT_FID_PAGE_SYNC_HOOK);
    }
    $worker = wp_next_scheduled(AIRPORT_FID_PAGE_WORKER_HOOK);
    if ($worker) {
        wp_unschedule_event($worker, AIRPORT_FID_PAGE_WORKER_HOOK);
    }
}
register_deactivation_hook(__FILE__, 'airport_fid_deactivation_cleanup');

function airport_fid_maybe_install() {
    global $wpdb;
    $table = $wpdb->prefix . AIRPORT_FID_CACHE_TABLE;
    $search_table = $wpdb->prefix . AIRPORT_FID_SEARCH_LOG_TABLE;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $search_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $search_table));
    if ($exists !== $table || $search_exists !== $search_table) {
        airport_fid_install();
        return;
    }

    $site_url_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$search_table} LIKE %s", 'site_url'));
    $site_label_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$search_table} LIKE %s", 'site_label'));
    if ($site_url_column !== 'site_url' || $site_label_column !== 'site_label') {
        airport_fid_install();
    }
}
add_action('plugins_loaded', 'airport_fid_maybe_install');

function airport_fid_add_cron_schedules($schedules) {
    if (!isset($schedules['airport_fid_weekly'])) {
        $schedules['airport_fid_weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display' => 'Once Weekly (Airport FID)',
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'airport_fid_add_cron_schedules');

function airport_fid_next_day_timestamp($day_name) {
    $day_name = strtolower((string) $day_name);
    $day_map = array(
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    );
    $target = isset($day_map[$day_name]) ? $day_map[$day_name] : 3;

    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $today_start = $now->setTime(0, 0, 0);
    $weekday = (int) $today_start->format('w');
    $days_ahead = ($target - $weekday + 7) % 7;
    $next = $today_start->modify('+' . $days_ahead . ' days')->setTime(3, 0, 0);
    if ($next <= $now) {
        $next = $next->modify('+7 days');
    }
    return $next->getTimestamp();
}

function airport_fid_schedule_airport_pages_sync($force = false) {
    $settings = airport_fid_get_settings();
    if ((int) ($settings['airport_pages_enabled'] ?? 1) !== 1) {
        airport_fid_deactivation_cleanup();
        return;
    }

    $next = airport_fid_next_day_timestamp($settings['cache_refresh_day'] ?? 'wednesday');
    $current = wp_next_scheduled(AIRPORT_FID_PAGE_SYNC_HOOK);
    if ($force && $current) {
        wp_unschedule_event($current, AIRPORT_FID_PAGE_SYNC_HOOK);
        $current = false;
    }

    if (!$current) {
        wp_schedule_event($next, 'airport_fid_weekly', AIRPORT_FID_PAGE_SYNC_HOOK);
    }
}
add_action('init', 'airport_fid_schedule_airport_pages_sync');

function airport_fid_after_settings_update($old, $new) {
    $old_day = isset($old['cache_refresh_day']) ? (string) $old['cache_refresh_day'] : 'wednesday';
    $new_day = isset($new['cache_refresh_day']) ? (string) $new['cache_refresh_day'] : 'wednesday';
    $old_enabled = isset($old['airport_pages_enabled']) ? (int) $old['airport_pages_enabled'] : 1;
    $new_enabled = isset($new['airport_pages_enabled']) ? (int) $new['airport_pages_enabled'] : 1;
    if ($old_day !== $new_day || $old_enabled !== $new_enabled) {
        airport_fid_schedule_airport_pages_sync(true);
    }
}
add_action('update_option_' . AIRPORT_FID_OPTION_KEY, 'airport_fid_after_settings_update', 10, 2);

function airport_fid_get_cache_table() {
    global $wpdb;
    return $wpdb->prefix . AIRPORT_FID_CACHE_TABLE;
}

function airport_fid_get_search_log_table() {
    global $wpdb;
    return $wpdb->prefix . AIRPORT_FID_SEARCH_LOG_TABLE;
}

function airport_fid_search_log_has_site_columns() {
    global $wpdb;
    $table = airport_fid_get_search_log_table();
    $site_url_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'site_url'));
    $site_label_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'site_label'));
    return $site_url_column === 'site_url' && $site_label_column === 'site_label';
}

function airport_fid_get_backfill_pending_count() {
    global $wpdb;
    $cache_table = airport_fid_get_cache_table();
    $search_table = airport_fid_get_search_log_table();

    if (!airport_fid_search_log_has_site_columns()) {
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}");
    }

    $sql = "
        SELECT COUNT(*)
        FROM {$cache_table} c
        LEFT JOIN {$search_table} s
            ON s.cache_id = c.id
           AND s.source = 'cache_backfill'
        WHERE s.id IS NULL
           OR COALESCE(s.site_url, '') = ''
           OR COALESCE(s.site_label, '') = ''
    ";

    return (int) $wpdb->get_var($sql);
}

function airport_fid_is_search_backfill_done() {
    if ((string) get_option(AIRPORT_FID_SEARCH_BACKFILL_DONE_OPTION, '0') !== '1') {
        return false;
    }
    return airport_fid_get_backfill_pending_count() === 0;
}

function airport_fid_get_last_refresh_day() {
    $settings = airport_fid_get_settings();
    $refresh_day = isset($settings['cache_refresh_day']) && $settings['cache_refresh_day']
        ? $settings['cache_refresh_day']
        : 'wednesday';
    $day_map = array(
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    );

    $target = isset($day_map[$refresh_day]) ? $day_map[$refresh_day] : 3;
    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $weekday = (int) $now->format('w'); // 0=Sun ... 6=Sat
    $days_since = $weekday >= $target ? ($weekday - $target) : ($weekday + (7 - $target));
    return $now->modify('-' . $days_since . ' days')->setTime(0, 0, 0);
}

function airport_fid_is_cache_stale($updated_at) {
    if (empty($updated_at)) {
        return true;
    }
    try {
        $tz = wp_timezone();
        $updated = new DateTimeImmutable($updated_at, $tz);
        $last_refresh = airport_fid_get_last_refresh_day();
        return $updated < $last_refresh;
    } catch (Exception $e) {
        return true;
    }
}

function airport_fid_get_cache($airport, $date, $sort) {
    global $wpdb;
    $table = airport_fid_get_cache_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT payload, updated_at FROM {$table} WHERE airport = %s AND flight_date = %s AND sort = %s LIMIT 1",
        $airport,
        $date,
        $sort
    ));
    if (!$row) {
        return null;
    }
    $payload = json_decode($row->payload, true);
    if (!is_array($payload)) {
        $payload = array();
    }
    return array(
        'payload' => $payload,
        'updated_at' => $row->updated_at,
        'stale' => airport_fid_is_cache_stale($row->updated_at),
    );
}

function airport_fid_get_cache_id($airport, $date, $sort) {
    global $wpdb;
    $table = airport_fid_get_cache_table();
    $cache_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE airport = %s AND flight_date = %s AND sort = %s LIMIT 1",
        $airport,
        $date,
        $sort
    ));
    return $cache_id ? (int) $cache_id : 0;
}

function airport_fid_set_cache($airport, $date, $sort, $payload) {
    global $wpdb;
    $table = airport_fid_get_cache_table();
    $wpdb->replace($table, array(
        'airport' => $airport,
        'flight_date' => $date,
        'sort' => $sort,
        'payload' => wp_json_encode($payload),
        'updated_at' => current_time('mysql'),
    ), array('%s', '%s', '%s', '%s', '%s'));
    return airport_fid_get_cache_id($airport, $date, $sort);
}

function airport_fid_default_settings() {
    return array(
        'api_key' => '',
        'default_airport' => 'MNL',
        'use_geolocation_default' => 0,
        'github_repo' => 'https://github.com/jkhliffz09/airport-fid/',
        'github_token' => '',
        'max_destinations' => 8,
        'max_flights' => 24,
        'cache_ttl_minutes' => 30,
        'cache_refresh_day' => 'wednesday',
        'airport_pages_enabled' => 1,
        'airport_ai_enabled' => 0,
        'airport_ai_provider' => 'openai',
        'airport_ai_openai_key' => '',
        'airport_ai_openai_model' => 'gpt-4o-mini',
        'airport_ai_claude_key' => '',
        'airport_ai_claude_model' => 'claude-sonnet-4-6',
        'analytics_site_label' => '',
        'analytics_remote_enabled' => 0,
        'analytics_remote_url' => '',
        'analytics_remote_key' => '',
        'analytics_hub_enabled' => 0,
        'analytics_hub_key' => '',
        'header_font_size' => 18,
        'field_font_size' => 14,
        'button_font_size' => 12,
        'result_main_font_size' => 16,
        'result_sub_font_size' => 13,
        'expanded_label_font_size' => 11,
        'expanded_value_font_size' => 13,
        'background_dark' => '#121212',
        'background_light' => '#f5f5f5',
        'row_dark_odd' => '#0f1216',
        'row_dark_even' => '#171717',
        'row_light_odd' => '#ffffff',
        'row_light_even' => '#f5f5f5',
        'accent_color' => '#00bcd4',
        'text_dark' => '#ffffff',
        'text_light' => '#0a0a0a',
        'logo_max_width' => 74,
        'logo_max_height' => 36,
        'first_col_width' => 84,
        'chevron_size' => 18,
        'enable_animation' => 1,
        'default_theme' => 'dark',
        'theme_mode' => 'auto',
    );
}

function airport_fid_get_settings() {
    $defaults = airport_fid_default_settings();
    $saved = get_option(AIRPORT_FID_OPTION_KEY, array());
    if (!is_array($saved)) {
        $saved = array();
    }
    return array_merge($defaults, $saved);
}

function airport_fid_update_settings($settings) {
    update_option(AIRPORT_FID_OPTION_KEY, $settings);
}

function airport_fid_register_settings() {
    register_setting('airport_fid_settings_group', AIRPORT_FID_OPTION_KEY, array(
        'type' => 'array',
        'sanitize_callback' => 'airport_fid_sanitize_settings',
        'default' => airport_fid_default_settings(),
    ));

    add_settings_section(
        'airport_fid_main_section',
        'API Settings',
        'airport_fid_settings_section_intro',
        'airport-fid-settings'
    );

    add_settings_field(
        'airport_fid_api_key',
        'FlightLookup API Key',
        'airport_fid_api_key_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_field(
        'airport_fid_default_airport',
        'Default Airport (IATA)',
        'airport_fid_default_airport_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_field(
        'airport_fid_use_geolocation_default',
        'Use Geolocation by Default',
        'airport_fid_use_geolocation_default_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_field(
        'airport_fid_max_destinations',
        'Max Destinations',
        'airport_fid_max_destinations_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_field(
        'airport_fid_max_flights',
        'Max Flights',
        'airport_fid_max_flights_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_field(
        'airport_fid_cache_ttl',
        'Cache (minutes)',
        'airport_fid_cache_ttl_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_field(
        'airport_fid_cache_refresh_day',
        'Cache Refresh Day',
        'airport_fid_cache_refresh_day_field',
        'airport-fid-settings',
        'airport_fid_main_section'
    );

    add_settings_section(
        'airport_fid_updates_section',
        'Updates',
        'airport_fid_updates_section_intro',
        'airport-fid-settings'
    );

    add_settings_field(
        'airport_fid_github_repo',
        'GitHub Repo URL',
        'airport_fid_github_repo_field',
        'airport-fid-settings',
        'airport_fid_updates_section'
    );

    add_settings_field(
        'airport_fid_github_token',
        'GitHub Token (optional)',
        'airport_fid_github_token_field',
        'airport-fid-settings',
        'airport_fid_updates_section'
    );
}
add_action('admin_init', 'airport_fid_register_settings');

function airport_fid_settings_section_intro() {
    echo '<p>Enter your FlightLookup subscription key and display defaults.</p>';
}

function airport_fid_updates_section_intro() {
    echo '<p>Configure GitHub-based updates (use a public repo URL and optional token for private repos).</p>';
}

function airport_fid_api_key_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="text" name="%s[api_key]" value="%s" class="regular-text" autocomplete="off" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        esc_attr($settings['api_key'])
    );
}

function airport_fid_default_airport_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="text" name="%s[default_airport]" value="%s" class="regular-text" maxlength="3" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        esc_attr($settings['default_airport'])
    );
}

function airport_fid_use_geolocation_default_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<label><input type="checkbox" name="%s[use_geolocation_default]" value="1" %s /> Use visitor location to pick nearest airport (falls back to Default Airport)</label>',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        checked(1, (int) $settings['use_geolocation_default'], false)
    );
}

function airport_fid_max_destinations_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="number" name="%s[max_destinations]" value="%d" class="small-text" min="0" max="500" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        (int) $settings['max_destinations']
    );
    echo '<p class="description">Set to 0 for no limit.</p>';
}

function airport_fid_max_flights_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="number" name="%s[max_flights]" value="%d" class="small-text" min="0" max="1000" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        (int) $settings['max_flights']
    );
    echo '<p class="description">Set to 0 for no limit.</p>';
}

function airport_fid_cache_ttl_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="number" name="%s[cache_ttl_minutes]" value="%d" class="small-text" min="1" max="1440" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        (int) $settings['cache_ttl_minutes']
    );
}

function airport_fid_cache_refresh_day_field() {
    $settings = airport_fid_get_settings();
    $days = array(
        'sunday' => 'Sunday',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
    );
    $current = isset($settings['cache_refresh_day']) ? $settings['cache_refresh_day'] : 'wednesday';
    echo '<select name="' . esc_attr(AIRPORT_FID_OPTION_KEY) . '[cache_refresh_day]" class="regular-text">';
    foreach ($days as $value => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($value),
            selected($current, $value, false),
            esc_html($label)
        );
    }
    echo '</select>';
    echo '<p class="description">Cached data refreshes after this day.</p>';
}

function airport_fid_github_repo_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="url" name="%s[github_repo]" value="%s" class="regular-text" placeholder="https://github.com/user/repo" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        esc_attr($settings['github_repo'])
    );
}

function airport_fid_github_token_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="password" name="%s[github_token]" value="%s" class="regular-text" autocomplete="off" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        esc_attr($settings['github_token'])
    );
}

function airport_fid_sanitize_settings($settings) {
    $defaults = airport_fid_default_settings();

    $clean = array();
    $clean['api_key'] = isset($settings['api_key']) ? sanitize_text_field($settings['api_key']) : $defaults['api_key'];
    $clean['default_airport'] = isset($settings['default_airport']) ? strtoupper(sanitize_text_field($settings['default_airport'])) : $defaults['default_airport'];
    $clean['use_geolocation_default'] = !empty($settings['use_geolocation_default']) ? 1 : 0;
    $clean['github_repo'] = isset($settings['github_repo']) ? airport_fid_normalize_github_repo($settings['github_repo']) : $defaults['github_repo'];
    $clean['github_token'] = isset($settings['github_token']) ? sanitize_text_field($settings['github_token']) : $defaults['github_token'];
    $clean['max_destinations'] = isset($settings['max_destinations']) ? max(0, (int) $settings['max_destinations']) : $defaults['max_destinations'];
    $clean['max_flights'] = isset($settings['max_flights']) ? max(0, (int) $settings['max_flights']) : $defaults['max_flights'];
    $clean['cache_ttl_minutes'] = isset($settings['cache_ttl_minutes']) ? max(1, (int) $settings['cache_ttl_minutes']) : $defaults['cache_ttl_minutes'];
    $allowed_days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
    $day_value = isset($settings['cache_refresh_day']) ? strtolower((string) $settings['cache_refresh_day']) : $defaults['cache_refresh_day'];
    $clean['cache_refresh_day'] = in_array($day_value, $allowed_days, true) ? $day_value : $defaults['cache_refresh_day'];
    $clean['airport_pages_enabled'] = !empty($settings['airport_pages_enabled']) ? 1 : 0;
    $clean['airport_ai_enabled'] = !empty($settings['airport_ai_enabled']) ? 1 : 0;
    $provider = isset($settings['airport_ai_provider']) ? strtolower((string) $settings['airport_ai_provider']) : $defaults['airport_ai_provider'];
    $clean['airport_ai_provider'] = in_array($provider, array('openai', 'claude'), true) ? $provider : $defaults['airport_ai_provider'];
    $clean['airport_ai_openai_key'] = isset($settings['airport_ai_openai_key']) ? sanitize_text_field((string) $settings['airport_ai_openai_key']) : $defaults['airport_ai_openai_key'];
    $clean['airport_ai_openai_model'] = isset($settings['airport_ai_openai_model']) ? sanitize_text_field((string) $settings['airport_ai_openai_model']) : $defaults['airport_ai_openai_model'];
    $clean['airport_ai_claude_key'] = isset($settings['airport_ai_claude_key']) ? sanitize_text_field((string) $settings['airport_ai_claude_key']) : $defaults['airport_ai_claude_key'];
    $clean['airport_ai_claude_model'] = isset($settings['airport_ai_claude_model']) ? sanitize_text_field((string) $settings['airport_ai_claude_model']) : $defaults['airport_ai_claude_model'];
    $clean['analytics_site_label'] = isset($settings['analytics_site_label']) ? sanitize_text_field((string) $settings['analytics_site_label']) : $defaults['analytics_site_label'];
    $clean['analytics_remote_enabled'] = !empty($settings['analytics_remote_enabled']) ? 1 : 0;
    $clean['analytics_remote_url'] = isset($settings['analytics_remote_url']) ? esc_url_raw(trim((string) $settings['analytics_remote_url'])) : $defaults['analytics_remote_url'];
    $clean['analytics_remote_key'] = isset($settings['analytics_remote_key']) ? sanitize_text_field((string) $settings['analytics_remote_key']) : $defaults['analytics_remote_key'];
    $clean['analytics_hub_enabled'] = !empty($settings['analytics_hub_enabled']) ? 1 : 0;
    $clean['analytics_hub_key'] = isset($settings['analytics_hub_key']) ? sanitize_text_field((string) $settings['analytics_hub_key']) : $defaults['analytics_hub_key'];
    $clean['header_font_size'] = isset($settings['header_font_size']) ? max(12, min(40, (int) $settings['header_font_size'])) : $defaults['header_font_size'];
    $clean['field_font_size'] = isset($settings['field_font_size']) ? max(10, min(28, (int) $settings['field_font_size'])) : $defaults['field_font_size'];
    $clean['button_font_size'] = isset($settings['button_font_size']) ? max(10, min(24, (int) $settings['button_font_size'])) : $defaults['button_font_size'];
    $clean['result_main_font_size'] = isset($settings['result_main_font_size']) ? max(12, min(28, (int) $settings['result_main_font_size'])) : $defaults['result_main_font_size'];
    $clean['result_sub_font_size'] = isset($settings['result_sub_font_size']) ? max(10, min(22, (int) $settings['result_sub_font_size'])) : $defaults['result_sub_font_size'];
    $clean['expanded_label_font_size'] = isset($settings['expanded_label_font_size']) ? max(9, min(22, (int) $settings['expanded_label_font_size'])) : $defaults['expanded_label_font_size'];
    $clean['expanded_value_font_size'] = isset($settings['expanded_value_font_size']) ? max(10, min(24, (int) $settings['expanded_value_font_size'])) : $defaults['expanded_value_font_size'];
    $clean['logo_max_width'] = isset($settings['logo_max_width']) ? max(24, min(220, (int) $settings['logo_max_width'])) : $defaults['logo_max_width'];
    $clean['logo_max_height'] = isset($settings['logo_max_height']) ? max(16, min(120, (int) $settings['logo_max_height'])) : $defaults['logo_max_height'];
    $clean['first_col_width'] = isset($settings['first_col_width']) ? max(56, min(220, (int) $settings['first_col_width'])) : $defaults['first_col_width'];
    $clean['chevron_size'] = isset($settings['chevron_size']) ? max(10, min(48, (int) $settings['chevron_size'])) : $defaults['chevron_size'];
    $clean['enable_animation'] = !empty($settings['enable_animation']) ? 1 : 0;

    $color_keys = array(
        'background_dark',
        'background_light',
        'row_dark_odd',
        'row_dark_even',
        'row_light_odd',
        'row_light_even',
        'accent_color',
        'text_dark',
        'text_light',
    );
    foreach ($color_keys as $key) {
        $value = isset($settings[$key]) ? sanitize_hex_color($settings[$key]) : $defaults[$key];
        $clean[$key] = $value ? $value : $defaults[$key];
    }

    $allowed_theme_modes = array('auto', 'light_only', 'dark_only');
    $mode = isset($settings['theme_mode']) ? strtolower((string) $settings['theme_mode']) : $defaults['theme_mode'];
    $clean['theme_mode'] = in_array($mode, $allowed_theme_modes, true) ? $mode : $defaults['theme_mode'];
    $allowed_default_themes = array('light', 'dark');
    $theme = isset($settings['default_theme']) ? strtolower((string) $settings['default_theme']) : $defaults['default_theme'];
    $clean['default_theme'] = in_array($theme, $allowed_default_themes, true) ? $theme : $defaults['default_theme'];

    return array_merge($defaults, $clean);
}

function airport_fid_register_menu() {
    $icon_url = airport_fid_get_admin_menu_icon_data_uri();
    add_menu_page(
        'Airport FID Board',
        'Airport FID Board',
        'manage_options',
        'airport-fid-settings',
        'airport_fid_render_settings_page',
        $icon_url,
        58
    );
}
add_action('admin_menu', 'airport_fid_register_menu');

function airport_fid_get_admin_menu_icon_data_uri() {
    $icon_path = plugin_dir_path(__FILE__) . 'assets/img/admin-menu-icon.svg';
    if (!file_exists($icon_path)) {
        return 'dashicons-schedule';
    }

    $svg = file_get_contents($icon_path);
    if ($svg === false || $svg === '') {
        return 'dashicons-schedule';
    }

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function airport_fid_plugin_action_links($links) {
    $settings_url = admin_url('admin.php?page=airport-fid-settings');
    array_unshift($links, '<a href="' . esc_url($settings_url) . '">Settings</a>');
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'airport_fid_plugin_action_links');

function airport_fid_render_settings_page() {
    $settings = airport_fid_get_settings();
    $hub_enabled = (int) ($settings['analytics_hub_enabled'] ?? 0) === 1;
    $primary_tab = $hub_enabled ? 'hub' : 'analytics';
    echo '<div class="airport-fid-admin">';
    echo '<h1>Airport FID Board Settings</h1>';
    if (isset($_GET['cache_updated']) && $_GET['cache_updated'] === '1') {
        echo '<div class="airport-fid-admin-notice is-success">Cache item updated.</div>';
    } elseif (isset($_GET['search_backfill']) && $_GET['search_backfill'] === '1') {
        $backfilled = isset($_GET['backfilled']) ? max(0, (int) $_GET['backfilled']) : 0;
        $updated = isset($_GET['updated']) ? max(0, (int) $_GET['updated']) : 0;
        $skipped = isset($_GET['skipped']) ? max(0, (int) $_GET['skipped']) : 0;
        echo '<div class="airport-fid-admin-notice is-success">Analytics backfill completed. Added: ' . esc_html((string) $backfilled) . ', Updated: ' . esc_html((string) $updated) . ', Skipped existing: ' . esc_html((string) $skipped) . '.</div>';
    } elseif (isset($_GET['search_backfill']) && $_GET['search_backfill'] === '0') {
        echo '<div class="airport-fid-admin-notice is-error">Analytics backfill failed.</div>';
    } elseif (isset($_GET['cache_error']) && $_GET['cache_error'] === 'invalid_json') {
        echo '<div class="airport-fid-admin-notice is-error">Invalid JSON. Please fix the payload format and try again.</div>';
    } elseif (isset($_GET['pages_sync']) && $_GET['pages_sync'] === 'queued') {
        echo '<div class="airport-fid-admin-notice is-success">Airport page generation queued. Processing will continue in the background.</div>';
    } elseif (isset($_GET['pages_sync']) && $_GET['pages_sync'] === '1') {
        $created = isset($_GET['pages_created']) ? (int) $_GET['pages_created'] : 0;
        $updated = isset($_GET['pages_updated']) ? (int) $_GET['pages_updated'] : 0;
        echo '<div class="airport-fid-admin-notice is-success">Airport pages sync completed. Created: ' . esc_html((string) $created) . ', Updated: ' . esc_html((string) $updated) . '.</div>';
    } elseif (isset($_GET['pages_sync']) && $_GET['pages_sync'] === '0') {
        echo '<div class="airport-fid-admin-notice is-error">Airport pages sync failed.</div>';
    }
    $queue_state = airport_fid_get_page_generation_state();
    if (is_array($queue_state) && !empty($queue_state['running'])) {
        $progress = airport_fid_get_queue_progress_data($queue_state);
        $current_airport = isset($queue_state['current_airport']) ? (string) $queue_state['current_airport'] : '';
        $last_status = isset($queue_state['last_status']) ? (string) $queue_state['last_status'] : '';
        echo '<div class="airport-fid-admin-notice is-success">Background generation in progress: ' . esc_html((string) $progress['processed']) . ' / ' . esc_html((string) $progress['total']) . ' airports processed.</div>';
        echo '<div class="airport-fid-queue-progress" data-running="1">';
        echo '<div class="airport-fid-queue-progress-head"><strong>Progress:</strong> ' . esc_html((string) $progress['percent']) . '%</div>';
        echo '<div class="airport-fid-queue-progress-bar"><span style="width:' . esc_attr((string) $progress['percent']) . '%"></span></div>';
        echo '<div class="airport-fid-queue-progress-meta">Created: ' . esc_html((string) ((int) ($queue_state['created'] ?? 0))) . ' | Updated: ' . esc_html((string) ((int) ($queue_state['updated'] ?? 0))) . ' | Skipped: ' . esc_html((string) ((int) ($queue_state['skipped'] ?? 0))) . '</div>';
        if ($current_airport !== '') {
            echo '<div class="airport-fid-queue-progress-meta">Current: ' . esc_html($current_airport) . '</div>';
        }
        if ($last_status !== '') {
            echo '<div class="airport-fid-queue-progress-meta">Last action: ' . esc_html($last_status) . '</div>';
        }
        echo '</div>';
    } else {
        $last = get_option(AIRPORT_FID_PAGE_LAST_RESULT_OPTION, array());
        if (is_array($last) && !empty($last['finished_at'])) {
            $created = isset($last['created']) ? (int) $last['created'] : 0;
            $updated = isset($last['updated']) ? (int) $last['updated'] : 0;
            $skipped = isset($last['skipped']) ? (int) $last['skipped'] : 0;
            echo '<div class="airport-fid-admin-notice is-success airport-fid-admin-notice-flex">';
            echo '<span>Last background run: Created ' . esc_html((string) $created) . ', Updated ' . esc_html((string) $updated) . ', Skipped ' . esc_html((string) $skipped) . '.</span>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="airport-fid-queue-ok-form">';
            echo '<input type="hidden" name="action" value="airport_fid_dismiss_page_generation_result" />';
            wp_nonce_field('airport_fid_dismiss_page_generation_result', 'airport_fid_dismiss_nonce');
            echo '<button type="submit" class="button airport-fid-queue-ok-button">OK</button>';
            echo '</form>';
            echo '</div>';
        }
    }
    echo '<form method="post" action="options.php">';
    settings_fields('airport_fid_settings_group');

    echo '<div class="airport-fid-admin-tabs">';
    if ($hub_enabled) {
        echo '<button type="button" class="airport-fid-admin-tab is-active" data-tab="hub">Hub</button>';
    }
    echo '<button type="button" class="airport-fid-admin-tab' . ($primary_tab === 'analytics' ? ' is-active' : '') . '" data-tab="analytics">Analytics</button>';
    echo '<button type="button" class="airport-fid-admin-tab" data-tab="typography">Typography</button>';
    echo '<button type="button" class="airport-fid-admin-tab" data-tab="layout">Layout</button>';
    echo '<button type="button" class="airport-fid-admin-tab" data-tab="cache">Cached Items</button>';
    echo '<button type="button" class="airport-fid-admin-tab" data-tab="general">General Settings</button>';
    echo '</div>';

    echo '<section class="airport-fid-admin-panel" data-panel="typography">';
    echo '<h2>Typography</h2>';
    echo '<div class="airport-fid-admin-grid">';
    airport_fid_admin_number_field('Header Font Size', 'header_font_size', (int) $settings['header_font_size'], 12, 40);
    airport_fid_admin_number_field('Field Font Size', 'field_font_size', (int) $settings['field_font_size'], 10, 28);
    airport_fid_admin_number_field('Button Font Size', 'button_font_size', (int) $settings['button_font_size'], 10, 24);
    airport_fid_admin_number_field('Main Row Font Size', 'result_main_font_size', (int) $settings['result_main_font_size'], 12, 28);
    airport_fid_admin_number_field('Subtext Font Size', 'result_sub_font_size', (int) $settings['result_sub_font_size'], 10, 22);
    airport_fid_admin_number_field('Expanded Label Font Size', 'expanded_label_font_size', (int) $settings['expanded_label_font_size'], 9, 22);
    airport_fid_admin_number_field('Expanded Value Font Size', 'expanded_value_font_size', (int) $settings['expanded_value_font_size'], 10, 24);
    echo '</div>';
    echo '</section>';

    echo '<section class="airport-fid-admin-panel" data-panel="layout">';
    echo '<h2>Layout</h2>';
    echo '<div class="airport-fid-admin-grid">';
    airport_fid_admin_color_field('Accent Color', 'accent_color', $settings['accent_color']);
    airport_fid_admin_color_field('Text (Dark Mode)', 'text_dark', $settings['text_dark']);
    airport_fid_admin_color_field('Text (Light Mode)', 'text_light', $settings['text_light']);
    airport_fid_admin_color_field('Background (Dark)', 'background_dark', $settings['background_dark']);
    airport_fid_admin_color_field('Background (Light)', 'background_light', $settings['background_light']);
    airport_fid_admin_color_field('Dark Row Odd', 'row_dark_odd', $settings['row_dark_odd']);
    airport_fid_admin_color_field('Dark Row Even', 'row_dark_even', $settings['row_dark_even']);
    airport_fid_admin_color_field('Light Row Odd', 'row_light_odd', $settings['row_light_odd']);
    airport_fid_admin_color_field('Light Row Even', 'row_light_even', $settings['row_light_even']);
    airport_fid_admin_number_field('Logo Max Width', 'logo_max_width', (int) $settings['logo_max_width'], 24, 220);
    airport_fid_admin_number_field('Logo Max Height', 'logo_max_height', (int) $settings['logo_max_height'], 16, 120);
    airport_fid_admin_number_field('First Column Width', 'first_col_width', (int) $settings['first_col_width'], 56, 220);
    airport_fid_admin_number_field('Chevron Size', 'chevron_size', (int) $settings['chevron_size'], 10, 48);
    airport_fid_admin_checkbox_field('Enable Flip Animation', 'enable_animation', (int) $settings['enable_animation']);
    airport_fid_admin_select_field('Default Theme', 'default_theme', $settings['default_theme'], array('dark' => 'Dark', 'light' => 'Light'));
    airport_fid_admin_select_field('Theme Mode', 'theme_mode', $settings['theme_mode'], array(
        'auto' => 'Allow toggle',
        'light_only' => 'Light only',
        'dark_only' => 'Dark only',
    ));
    echo '</div>';
    echo '</section>';

    echo '<section class="airport-fid-admin-panel" data-panel="general">';
    echo '<h2>General Settings</h2>';
    echo '<div class="airport-fid-admin-grid">';
    airport_fid_admin_text_field('FlightLookup API Key', 'api_key', $settings['api_key']);
    airport_fid_admin_text_field('Default Airport (IATA)', 'default_airport', $settings['default_airport'], array('maxlength' => '3'));
    airport_fid_admin_checkbox_field('Use Geolocation by Default', 'use_geolocation_default', (int) $settings['use_geolocation_default']);
    airport_fid_admin_number_field('Max Destinations (0 = unlimited)', 'max_destinations', (int) $settings['max_destinations'], 0, 500);
    airport_fid_admin_number_field('Max Flights (0 = unlimited)', 'max_flights', (int) $settings['max_flights'], 0, 1000);
    airport_fid_admin_number_field('Cache TTL (minutes)', 'cache_ttl_minutes', (int) $settings['cache_ttl_minutes'], 1, 1440);
    airport_fid_admin_select_field('Cache Refresh Day', 'cache_refresh_day', $settings['cache_refresh_day'], array(
        'sunday' => 'Sunday',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
    ));
    airport_fid_admin_checkbox_field('Enable Weekly Airport Page Sync', 'airport_pages_enabled', (int) $settings['airport_pages_enabled']);
    airport_fid_admin_checkbox_field('Enable AI About Section', 'airport_ai_enabled', (int) $settings['airport_ai_enabled']);
    airport_fid_admin_select_field('AI Provider', 'airport_ai_provider', $settings['airport_ai_provider'], array(
        'openai' => 'OpenAI',
        'claude' => 'Claude',
    ));
    airport_fid_admin_text_field('OpenAI API Key', 'airport_ai_openai_key', $settings['airport_ai_openai_key'], array('type' => 'password', 'autocomplete' => 'off'));
    airport_fid_admin_text_field('OpenAI Model', 'airport_ai_openai_model', $settings['airport_ai_openai_model']);
    airport_fid_admin_text_field('Claude API Key', 'airport_ai_claude_key', $settings['airport_ai_claude_key'], array('type' => 'password', 'autocomplete' => 'off'));
    airport_fid_admin_text_field('Claude Model', 'airport_ai_claude_model', $settings['airport_ai_claude_model']);
    airport_fid_admin_text_field('Analytics Site Label', 'analytics_site_label', $settings['analytics_site_label']);
    airport_fid_admin_checkbox_field('Enable Remote Analytics Sender', 'analytics_remote_enabled', (int) $settings['analytics_remote_enabled']);
    airport_fid_admin_text_field('Remote Analytics Hub URL', 'analytics_remote_url', $settings['analytics_remote_url'], array('type' => 'url', 'placeholder' => 'https://example.com'));
    airport_fid_admin_text_field('Remote Analytics Hub Key', 'analytics_remote_key', $settings['analytics_remote_key'], array('type' => 'password', 'autocomplete' => 'off'));
    airport_fid_admin_checkbox_field('Enable Analytics Hub Receiver', 'analytics_hub_enabled', (int) $settings['analytics_hub_enabled']);
    airport_fid_admin_text_field('Analytics Hub Key', 'analytics_hub_key', $settings['analytics_hub_key'], array('type' => 'password', 'autocomplete' => 'off'));
    airport_fid_admin_text_field('GitHub Repo URL', 'github_repo', $settings['github_repo']);
    airport_fid_admin_text_field('GitHub Token (optional)', 'github_token', $settings['github_token']);
    echo '</div>';
    echo '<div class="airport-fid-admin-actions" data-settings-actions="1">';
    submit_button('Save Settings', 'primary', 'submit', false);
    echo '</div>';
    echo '</section>';
    echo '</form>';
    echo '<section class="airport-fid-admin-panel' . ($primary_tab === 'analytics' ? ' is-active' : '') . '" data-panel="analytics">';
    airport_fid_render_analytics_section();
    echo '</section>';
    if ($hub_enabled) {
        echo '<section class="airport-fid-admin-panel is-active" data-panel="hub">';
        airport_fid_render_hub_analytics_section();
        echo '</section>';
    }
    echo '<section class="airport-fid-admin-panel" data-panel="cache">';
    echo '<section class="airport-fid-admin-cache-section">';
    echo '<h2>Cached Request Items</h2>';
    airport_fid_render_cache_items_table();
    echo '</section>';
    echo '<section class="airport-fid-admin-cache-section">';
    echo '<h2>Airport Pages</h2>';
    echo '<p>Generate or update one page per cached airport using the dynamic airport schedule template.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="airport-fid-generate-pages-form" data-ai-enabled="' . esc_attr((string) ((int) $settings['airport_ai_enabled'])) . '" data-ai-provider="' . esc_attr((string) $settings['airport_ai_provider']) . '">';
    echo '<input type="hidden" name="action" value="airport_fid_generate_airport_pages" />';
    wp_nonce_field('airport_fid_generate_airport_pages', 'airport_fid_pages_nonce');
    submit_button('Generate/Update Airport Pages', 'secondary', 'submit', false);
    echo '</form>';
    echo '</section>';
    echo '</section>';
    echo '<div class="airport-fid-admin-overlay" aria-hidden="true">';
    echo '<div class="airport-fid-admin-dialog" role="status" aria-live="polite">';
    echo '<div class="airport-fid-admin-spinner"></div>';
    echo '<h3>Generating Airport Pages</h3>';
    echo '<p class="airport-fid-admin-dialog-text">Initializing...</p>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

function airport_fid_get_queue_progress_data($state) {
    $total = isset($state['total']) ? (int) $state['total'] : 0;
    $remaining = isset($state['airports']) && is_array($state['airports']) ? count($state['airports']) : 0;
    $processed = max(0, $total - $remaining);
    $percent = 0;
    if ($total > 0) {
        $percent = (int) round(($processed / $total) * 100);
    } elseif (empty($state['running'])) {
        $percent = 100;
    }
    return array(
        'total' => $total,
        'remaining' => $remaining,
        'processed' => $processed,
        'percent' => min(100, max(0, $percent)),
    );
}

function airport_fid_render_cache_items_table() {
    global $wpdb;
    $table = airport_fid_get_cache_table();
    $rows = $wpdb->get_results("SELECT id, airport, flight_date, sort, payload, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A);

    if (empty($rows)) {
        echo '<p class="airport-fid-admin-empty">No cached items found.</p>';
        return;
    }

    echo '<div class="airport-fid-admin-table-wrap">';
    echo '<table class="airport-fid-admin-table">';
    echo '<thead><tr>';
    echo '<th>Airport</th>';
    echo '<th>Date</th>';
    echo '<th>Sort</th>';
    echo '<th>Flights</th>';
    echo '<th>Updated</th>';
    echo '<th>JSON Payload</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $flights_count = 0;
        if (!empty($row['payload'])) {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded) && isset($decoded['flights']) && is_array($decoded['flights'])) {
                $flights_count = count($decoded['flights']);
            }
        }

        $date_display = $row['flight_date'];
        if (preg_match('/^\d{8}$/', $date_display)) {
            $date_display = substr($date_display, 0, 4) . '-' . substr($date_display, 4, 2) . '-' . substr($date_display, 6, 2);
        }

        echo '<tr>';
        echo '<td>' . esc_html($row['airport']) . '</td>';
        echo '<td>' . esc_html($date_display) . '</td>';
        echo '<td>' . esc_html($row['sort']) . '</td>';
        echo '<td>' . esc_html((string) $flights_count) . '</td>';
        echo '<td>' . esc_html($row['updated_at']) . '</td>';
        $textarea_id = 'airport-fid-payload-' . (int) $row['id'];
        echo '<td class="airport-fid-admin-json-cell">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="airport-fid-admin-json-form">';
        echo '<input type="hidden" name="action" value="airport_fid_update_cache_item" />';
        echo '<input type="hidden" name="cache_id" value="' . esc_attr((string) $row['id']) . '" />';
        wp_nonce_field('airport_fid_update_cache_item_' . (string) $row['id'], 'airport_fid_cache_nonce');
        $pretty_json = json_encode(json_decode((string) $row['payload'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($pretty_json === false || $pretty_json === 'null') {
            $pretty_json = (string) $row['payload'];
        }
        echo '<textarea id="' . esc_attr($textarea_id) . '" name="payload" rows="8">' . esc_textarea($pretty_json) . '</textarea>';
        echo '<div class="airport-fid-admin-json-actions">';
        echo '<button type="button" class="button airport-fid-export-csv" data-target="' . esc_attr($textarea_id) . '" data-airport="' . esc_attr($row['airport']) . '" data-date="' . esc_attr((string) $row['flight_date']) . '">Export CSV</button>';
        echo '<button type="button" class="button airport-fid-import-csv" data-target="' . esc_attr($textarea_id) . '" data-input="airport-fid-csv-input-' . esc_attr((string) $row['id']) . '">Import CSV</button>';
        echo '<input id="airport-fid-csv-input-' . esc_attr((string) $row['id']) . '" class="airport-fid-import-csv-input" type="file" accept=".csv,text/csv" data-target="' . esc_attr($textarea_id) . '" />';
        echo '<button type="submit" class="button button-primary">Update JSON</button>';
        echo '</div>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

function airport_fid_update_cache_item() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized request.');
    }

    $cache_id = isset($_POST['cache_id']) ? (int) $_POST['cache_id'] : 0;
    $payload_raw = isset($_POST['payload']) ? wp_unslash((string) $_POST['payload']) : '';
    if ($cache_id <= 0) {
        wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings'));
        exit;
    }
    if (!isset($_POST['airport_fid_cache_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $_POST['airport_fid_cache_nonce']), 'airport_fid_update_cache_item_' . $cache_id)) {
        wp_die('Invalid security token.');
    }

    $decoded = json_decode($payload_raw, true);
    if (!is_array($decoded)) {
        wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&cache_error=invalid_json'));
        exit;
    }

    global $wpdb;
    $table = airport_fid_get_cache_table();
    $wpdb->update(
        $table,
        array(
            'payload' => wp_json_encode($decoded),
            'updated_at' => current_time('mysql'),
        ),
        array('id' => $cache_id),
        array('%s', '%s'),
        array('%d')
    );

    wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&cache_updated=1'));
    exit;
}
add_action('admin_post_airport_fid_update_cache_item', 'airport_fid_update_cache_item');

function airport_fid_backfill_search_log() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized request.');
    }
    if (!isset($_POST['airport_fid_backfill_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $_POST['airport_fid_backfill_nonce']), 'airport_fid_backfill_search_log')) {
        wp_die('Invalid security token.');
    }

    if (airport_fid_is_search_backfill_done()) {
        wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings'));
        exit;
    }

    global $wpdb;
    $cache_table = airport_fid_get_cache_table();
    $search_table = airport_fid_get_search_log_table();
    $rows = $wpdb->get_results("SELECT id, airport, flight_date, sort, payload, updated_at FROM {$cache_table} ORDER BY updated_at ASC, id ASC", ARRAY_A);

    $backfilled = 0;
    $updated = 0;
    $skipped = 0;
    $identity = airport_fid_get_site_identity();

    foreach ($rows as $row) {
        $cache_id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($cache_id <= 0) {
            continue;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, site_url, site_label FROM {$search_table} WHERE cache_id = %d AND source = %s LIMIT 1",
            $cache_id,
            'cache_backfill'
        ), ARRAY_A);

        $selected_airport = '';
        if (!empty($row['payload'])) {
            $payload = json_decode((string) $row['payload'], true);
            if (is_array($payload)) {
                $selected_airport = sanitize_text_field((string) ($payload['airport_name'] ?? ''));
            }
        }

        $created_at = !empty($row['updated_at']) ? sanitize_text_field((string) $row['updated_at']) : current_time('mysql');
        $log_payload = array(
            'cache_id' => $cache_id,
            'airport' => strtoupper(sanitize_text_field((string) ($row['airport'] ?? ''))),
            'date' => sanitize_text_field((string) ($row['flight_date'] ?? '')),
            'sort' => sanitize_text_field((string) ($row['sort'] ?? 'departure_time')),
            'source' => 'cache_backfill',
            'raw_input' => strtoupper(sanitize_text_field((string) ($row['airport'] ?? ''))),
            'selected_airport' => $selected_airport,
            'created_at' => $created_at,
            'site_url' => $identity['site_url'],
            'site_label' => $identity['site_label'],
        );

        if ($existing && isset($existing['id'])) {
            $result = $wpdb->update($search_table, array(
                'airport' => $log_payload['airport'],
                'flight_date' => $log_payload['date'],
                'sort' => $log_payload['sort'],
                'raw_input' => $log_payload['raw_input'],
                'selected_airport' => $log_payload['selected_airport'],
                'site_url' => $log_payload['site_url'],
                'site_label' => $log_payload['site_label'],
                'created_at' => $log_payload['created_at'],
            ), array(
                'id' => (int) $existing['id'],
            ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'), array('%d'));

            if ($result === false) {
                wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&search_backfill=0'));
                exit;
            }

            airport_fid_send_remote_search_log(array(
                'airport' => $log_payload['airport'],
                'date' => $log_payload['date'],
                'sort' => $log_payload['sort'],
                'source' => $log_payload['source'],
                'raw_input' => $log_payload['raw_input'],
                'selected_airport' => $log_payload['selected_airport'],
                'site_url' => $log_payload['site_url'],
                'site_label' => $log_payload['site_label'],
                'created_at' => $log_payload['created_at'],
            ));
            $updated++;
            continue;
        }

        $log_id = airport_fid_log_search($log_payload);
        if ($log_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&search_backfill=0'));
            exit;
        }

        $backfilled++;
    }

    update_option(AIRPORT_FID_SEARCH_BACKFILL_DONE_OPTION, '1', false);
    wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&search_backfill=1&backfilled=' . $backfilled . '&updated=' . $updated . '&skipped=' . $skipped));
    exit;
}
add_action('admin_post_airport_fid_backfill_search_log', 'airport_fid_backfill_search_log');

function airport_fid_dismiss_page_generation_result() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized request.');
    }
    if (!isset($_POST['airport_fid_dismiss_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $_POST['airport_fid_dismiss_nonce']), 'airport_fid_dismiss_page_generation_result')) {
        wp_die('Invalid security token.');
    }

    delete_option(AIRPORT_FID_PAGE_LAST_RESULT_OPTION);
    wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings'));
    exit;
}
add_action('admin_post_airport_fid_dismiss_page_generation_result', 'airport_fid_dismiss_page_generation_result');

function airport_fid_handle_generate_airport_pages() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized request.');
    }
    if (!isset($_POST['airport_fid_pages_nonce']) || !wp_verify_nonce(sanitize_text_field((string) $_POST['airport_fid_pages_nonce']), 'airport_fid_generate_airport_pages')) {
        wp_die('Invalid security token.');
    }

    $queued = airport_fid_start_airport_pages_generation('manual');
    if (is_wp_error($queued)) {
        wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&pages_sync=0'));
        exit;
    }
    wp_safe_redirect(admin_url('admin.php?page=airport-fid-settings&pages_sync=queued'));
    exit;
}
add_action('admin_post_airport_fid_generate_airport_pages', 'airport_fid_handle_generate_airport_pages');
add_action(AIRPORT_FID_PAGE_SYNC_HOOK, 'airport_fid_kickoff_scheduled_airport_pages_generation');
add_action(AIRPORT_FID_PAGE_WORKER_HOOK, 'airport_fid_process_airport_pages_generation_queue');

function airport_fid_kickoff_scheduled_airport_pages_generation() {
    airport_fid_start_airport_pages_generation('scheduled');
}

function airport_fid_get_page_generation_state() {
    $state = get_option(AIRPORT_FID_PAGE_QUEUE_OPTION, array());
    return is_array($state) ? $state : array();
}

function airport_fid_set_page_generation_state($state) {
    if (!is_array($state)) {
        $state = array();
    }
    update_option(AIRPORT_FID_PAGE_QUEUE_OPTION, $state, false);
}

function airport_fid_schedule_page_generation_worker($delay = 5) {
    if (!wp_next_scheduled(AIRPORT_FID_PAGE_WORKER_HOOK)) {
        wp_schedule_single_event(time() + max(1, (int) $delay), AIRPORT_FID_PAGE_WORKER_HOOK);
    }
}

function airport_fid_maybe_recover_page_generation_worker() {
    if (!is_admin()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    $state = airport_fid_get_page_generation_state();
    if (empty($state['running']) || empty($state['airports']) || !is_array($state['airports'])) {
        return;
    }
    if (wp_next_scheduled(AIRPORT_FID_PAGE_WORKER_HOOK)) {
        return;
    }
    airport_fid_schedule_page_generation_worker(1);
    if (function_exists('spawn_cron')) {
        @spawn_cron(time());
    }
}
add_action('admin_init', 'airport_fid_maybe_recover_page_generation_worker');

function airport_fid_process_queue_on_settings_page() {
    if (!is_admin()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!isset($_GET['page']) || sanitize_text_field((string) $_GET['page']) !== 'airport-fid-settings') {
        return;
    }

    $state = airport_fid_get_page_generation_state();
    if (empty($state['running']) || empty($state['airports']) || !is_array($state['airports'])) {
        return;
    }

    $last_tick = isset($state['last_tick']) ? (int) $state['last_tick'] : 0;
    $now = time();
    if ($last_tick > 0 && ($now - $last_tick) < 2) {
        return;
    }
    airport_fid_process_airport_pages_generation_queue(1);
}
add_action('admin_init', 'airport_fid_process_queue_on_settings_page', 20);

function airport_fid_start_airport_pages_generation($source = 'manual') {
    $settings = airport_fid_get_settings();
    if ((int) ($settings['airport_pages_enabled'] ?? 1) !== 1) {
        return new WP_Error('airport_fid_pages_disabled', 'Airport page generation is disabled.');
    }

    $state = airport_fid_get_page_generation_state();
    if (!empty($state['running'])) {
        return array('queued' => true, 'already_running' => true);
    }

    $airports = airport_fid_get_cached_airports();
    if (empty($airports)) {
        return array('queued' => false, 'empty' => true);
    }

    $state = array(
        'running' => 1,
        'source' => (string) $source,
        'started_at' => current_time('mysql'),
        'last_tick' => time(),
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => count($airports),
        'airports' => array_values($airports),
        'current_airport' => '',
        'last_status' => 'Queue initialized',
    );
    airport_fid_set_page_generation_state($state);
    if ($source === 'manual') {
        // Process one airport immediately to avoid getting stuck at 0 progress if WP-Cron is delayed.
        airport_fid_process_airport_pages_generation_queue(1);
    }
    airport_fid_schedule_page_generation_worker(2);
    if (function_exists('spawn_cron')) {
        @spawn_cron(time());
    }

    return array('queued' => true, 'already_running' => false, 'total' => count($airports));
}

function airport_fid_process_airport_pages_generation_queue($batch_limit = null) {
    $state = airport_fid_get_page_generation_state();
    if (empty($state['running']) || empty($state['airports']) || !is_array($state['airports'])) {
        return;
    }

    $batch_size = $batch_limit !== null ? max(1, (int) $batch_limit) : 2;
    $processed = 0;
    $start = microtime(true);
    while ($processed < $batch_size && !empty($state['airports'])) {
        $airport = array_shift($state['airports']);
        $state['current_airport'] = (string) $airport;
        $dataset = airport_fid_build_airport_dataset($airport);
        if (empty($dataset) || empty($dataset['flights'])) {
            $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
            $state['last_status'] = (string) $airport . ': skipped (no flights found)';
        } else {
            $result = airport_fid_upsert_airport_page($airport, $dataset);
            if (is_array($result) && isset($result['state']) && $result['state'] === 'created') {
                $state['created'] = (int) ($state['created'] ?? 0) + 1;
                $state['last_status'] = (string) $airport . ': page created';
            } elseif (is_array($result) && isset($result['state']) && $result['state'] === 'updated') {
                $state['updated'] = (int) ($state['updated'] ?? 0) + 1;
                $state['last_status'] = (string) $airport . ': page updated';
            } else {
                $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
                $state['last_status'] = (string) $airport . ': skipped (save failed)';
            }
        }
        $processed++;
        if ((microtime(true) - $start) > 12) {
            break;
        }
    }
    $state['last_tick'] = time();

    if (empty($state['airports'])) {
        $state['running'] = 0;
        $state['finished_at'] = current_time('mysql');
        airport_fid_set_page_generation_state($state);
        update_option(AIRPORT_FID_PAGE_LAST_RESULT_OPTION, array(
            'created' => (int) ($state['created'] ?? 0),
            'updated' => (int) ($state['updated'] ?? 0),
            'skipped' => (int) ($state['skipped'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
            'finished_at' => (string) ($state['finished_at'] ?? current_time('mysql')),
        ), false);
        return;
    }

    airport_fid_set_page_generation_state($state);
    airport_fid_schedule_page_generation_worker(8);
}

function airport_fid_generate_airport_pages() {
    // Backward-compatible wrapper: queue background generation instead of blocking request.
    $result = airport_fid_start_airport_pages_generation('manual');
    if (is_wp_error($result)) {
        return $result;
    }
    return array(
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'queued' => true,
    );
}

function airport_fid_get_cached_airports() {
    global $wpdb;
    $table = airport_fid_get_cache_table();
    $rows = $wpdb->get_col("SELECT DISTINCT airport FROM {$table} ORDER BY airport ASC");
    if (!is_array($rows)) {
        return array();
    }

    $airports = array();
    foreach ($rows as $airport) {
        $airport = strtoupper(trim((string) $airport));
        if ($airport !== '') {
            $airports[] = $airport;
        }
    }
    return array_values(array_unique($airports));
}

function airport_fid_build_airport_dataset($airport) {
    global $wpdb;
    $table = airport_fid_get_cache_table();
    $dates = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT flight_date FROM {$table} WHERE airport = %s ORDER BY flight_date DESC LIMIT 20",
        $airport
    ));
    if (!is_array($dates) || empty($dates)) {
        return array();
    }

    $fallback_dataset = array();
    foreach ($dates as $date) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT payload, updated_at FROM {$table} WHERE airport = %s AND flight_date = %s ORDER BY updated_at DESC",
            $airport,
            $date
        ), ARRAY_A);
        if (empty($rows)) {
            continue;
        }

        $airport_name = '';
        $latest_updated_at = '';
        $flight_map = array();

        foreach ($rows as $row) {
            if ($latest_updated_at === '' || strtotime((string) $row['updated_at']) > strtotime($latest_updated_at)) {
                $latest_updated_at = (string) $row['updated_at'];
            }
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            if ($airport_name === '' && !empty($payload['airport_name'])) {
                $airport_name = (string) $payload['airport_name'];
            }
            $flights = isset($payload['flights']) && is_array($payload['flights']) ? $payload['flights'] : array();
            foreach ($flights as $flight) {
                if (!is_array($flight)) {
                    continue;
                }
                if ($airport_name === '' && !empty($flight['origin_name'])) {
                    $airport_name = (string) $flight['origin_name'];
                }
                $key = strtoupper((string) ($flight['flight_number'] ?? ''))
                    . '|' . (string) ((int) ($flight['departure_ts'] ?? 0))
                    . '|' . strtoupper((string) ($flight['destination'] ?? ''))
                    . '|' . (string) ((int) ($flight['arrival_ts'] ?? 0));
                if (!isset($flight_map[$key])) {
                    $flight_map[$key] = $flight;
                }
            }
        }

        $flights = array_values($flight_map);
        usort($flights, function ($a, $b) {
            $a_ts = isset($a['departure_ts']) ? (int) $a['departure_ts'] : 0;
            $b_ts = isset($b['departure_ts']) ? (int) $b['departure_ts'] : 0;
            if ($a_ts === $b_ts) {
                return 0;
            }
            return $a_ts < $b_ts ? -1 : 1;
        });

        $dataset = array(
            'airport' => $airport,
            'airport_name' => $airport_name ?: $airport,
            'flight_date' => (string) $date,
            'updated_at' => $latest_updated_at,
            'flights' => $flights,
        );

        if (empty($fallback_dataset)) {
            $fallback_dataset = $dataset;
        }
        if (!empty($flights)) {
            return $dataset;
        }
    }

    return $fallback_dataset;
}

function airport_fid_upsert_airport_page($airport, $dataset) {
    $airport = strtoupper((string) $airport);
    $title = $airport . ' Airport Flight Schedules';
    $slug = strtolower($airport) . '-airport-flight-schedules';
    $content = airport_fid_render_airport_page_content($dataset);

    $existing = airport_fid_get_airport_page_by_code($airport);
    if (!$existing) {
        $existing = get_page_by_path($slug, OBJECT, 'page');
    }

    $postarr = array(
        'post_title' => $title,
        'post_name' => $slug,
        'post_type' => 'page',
        'post_content' => $content,
        'post_status' => 'publish',
    );

    if ($existing && isset($existing->ID)) {
        $postarr['ID'] = (int) $existing->ID;
        $postarr['post_status'] = $existing->post_status ?: 'publish';
        $post_id = wp_update_post($postarr, true);
        if (is_wp_error($post_id)) {
            return array('state' => 'skipped', 'post_id' => 0);
        }
        update_post_meta($post_id, AIRPORT_FID_PAGE_META_FLAG, '1');
        update_post_meta($post_id, AIRPORT_FID_PAGE_META_AIRPORT, $airport);
        update_post_meta($post_id, '_airport_fid_generated_at', current_time('mysql'));
        airport_fid_generate_and_attach_featured_image($post_id, $dataset);
        return array('state' => 'updated', 'post_id' => (int) $post_id);
    }

    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id) || !$post_id) {
        return array('state' => 'skipped', 'post_id' => 0);
    }

    update_post_meta($post_id, AIRPORT_FID_PAGE_META_FLAG, '1');
    update_post_meta($post_id, AIRPORT_FID_PAGE_META_AIRPORT, $airport);
    update_post_meta($post_id, '_airport_fid_generated_at', current_time('mysql'));
    airport_fid_generate_and_attach_featured_image($post_id, $dataset);
    return array('state' => 'created', 'post_id' => (int) $post_id);
}

function airport_fid_get_airport_page_by_code($airport) {
    $query = new WP_Query(array(
        'post_type' => 'page',
        'post_status' => array('publish', 'draft', 'private'),
        'posts_per_page' => 1,
        'meta_query' => array(
            array(
                'key' => AIRPORT_FID_PAGE_META_AIRPORT,
                'value' => strtoupper((string) $airport),
            ),
        ),
        'fields' => 'all',
        'no_found_rows' => true,
    ));

    if ($query->have_posts()) {
        return $query->posts[0];
    }
    return null;
}

function airport_fid_format_human_date_from_ymd($ymd) {
    if (!preg_match('/^\d{8}$/', (string) $ymd)) {
        return '';
    }
    try {
        $dt = new DateTimeImmutable(substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2));
        return $dt->format('M j, Y');
    } catch (Exception $e) {
        return $ymd;
    }
}

function airport_fid_render_airport_page_content($dataset) {
    $airport = strtoupper((string) ($dataset['airport'] ?? ''));
    $airport_name = (string) ($dataset['airport_name'] ?? $airport);
    $flights = isset($dataset['flights']) && is_array($dataset['flights']) ? $dataset['flights'] : array();
    $flight_date = (string) ($dataset['flight_date'] ?? '');
    $flight_date_human = airport_fid_format_human_date_from_ymd($flight_date);

    $airline_counts = array();
    $destination_counts = array();
    $destination_airlines = array();

    foreach ($flights as $flight) {
        if (!is_array($flight)) {
            continue;
        }
        $airline_code = strtoupper(trim((string) ($flight['airline_code'] ?? '')));
        $airline_name = trim((string) ($flight['airline'] ?? ''));
        $airline_key = $airline_code !== '' ? $airline_code : $airline_name;
        if ($airline_key !== '') {
            if (!isset($airline_counts[$airline_key])) {
                $airline_counts[$airline_key] = array(
                    'code' => $airline_code,
                    'name' => $airline_name ?: $airline_code,
                    'count' => 0,
                );
            }
            $airline_counts[$airline_key]['count']++;
        }

        $dest_code = strtoupper(trim((string) ($flight['destination'] ?? '')));
        $dest_name = trim((string) ($flight['destination_name'] ?? $dest_code));
        if ($dest_code !== '') {
            if (!isset($destination_counts[$dest_code])) {
                $destination_counts[$dest_code] = array(
                    'code' => $dest_code,
                    'name' => $dest_name ?: $dest_code,
                    'count' => 0,
                );
                $destination_airlines[$dest_code] = array();
            }
            $destination_counts[$dest_code]['count']++;
            if ($airline_key !== '') {
                $destination_airlines[$dest_code][$airline_key] = true;
            }
        }
    }

    uasort($airline_counts, function ($a, $b) {
        if ((int) $a['count'] === (int) $b['count']) {
            return strcmp((string) $a['name'], (string) $b['name']);
        }
        return ((int) $a['count'] > (int) $b['count']) ? -1 : 1;
    });
    uasort($destination_counts, function ($a, $b) {
        if ((int) $a['count'] === (int) $b['count']) {
            return strcmp((string) $a['name'], (string) $b['name']);
        }
        return ((int) $a['count'] > (int) $b['count']) ? -1 : 1;
    });

    $stats_flights = count($flights);
    $stats_airlines = count($airline_counts);
    $stats_destinations = count($destination_counts);

    $airline_html = '';
    $airline_slice = array_slice($airline_counts, 0, 40);
    foreach ($airline_slice as $airline) {
        $iata = trim((string) $airline['code']);
        $name = trim((string) $airline['name']);
        $airline_html .= '<div class="pr-chip"><span class="pr-iata">' . esc_html($iata !== '' ? $iata : '--') . '</span>' . esc_html($name) . '</div>';
    }

    $routes_html = '';
    $route_slice = array_slice($destination_counts, 0, 20);
    foreach ($route_slice as $dest) {
        $code = (string) $dest['code'];
        $name = (string) $dest['name'];
        $count = (int) $dest['count'];
        $airlines = array();
        if (isset($destination_airlines[$code]) && is_array($destination_airlines[$code])) {
            $airlines = array_keys($destination_airlines[$code]);
        }
        sort($airlines, SORT_STRING);
        $routes_html .= '<tr>';
        $routes_html .= '<td><div class="pr-dest">' . esc_html($name) . '</div></td>';
        $routes_html .= '<td class="pr-code">' . esc_html($code) . '</td>';
        $routes_html .= '<td>' . esc_html((string) $count) . '</td>';
        $routes_html .= '<td>' . esc_html(implode(', ', $airlines)) . '</td>';
        $routes_html .= '</tr>';
    }

    $updated_line = $flight_date_human !== '' ? $flight_date_human : 'latest cache';
    $about_html = airport_fid_generate_about_text($dataset, $stats_flights, $stats_airlines, $stats_destinations);

    $content = '';
    $content .= '<div class="pr-airport">';
    $content .= '<div class="pr-hero">';
    $content .= '<div class="pr-breadcrumb"><a href="/airport-flight-schedules/">Airport Schedules</a> &rsaquo; ' . esc_html($airport) . '</div>';
    $content .= '<h1 class="pr-title">' . esc_html($airport) . ' &mdash; ' . esc_html($airport_name) . ' <span class="pr-accent">Flight Schedule</span> &amp; Departure Board</h1>';
    $content .= '<p class="pr-subtitle">Flight schedules, departures, airlines, and top routes for ' . esc_html($airport_name) . ' (' . esc_html($airport) . ').</p>';
    $content .= '<div class="pr-meta"><span>&#9992; ' . esc_html((string) $stats_airlines) . ' scheduled airlines</span><span>&#128197; Data: cache date ' . esc_html($updated_line) . '</span></div>';
    $content .= '<div class="pr-stat-row">';
    $content .= '<div class="pr-stat"><span class="pr-stat-num">' . esc_html(number_format_i18n($stats_flights)) . '</span><span class="pr-stat-label">Flights</span></div>';
    $content .= '<div class="pr-stat"><span class="pr-stat-num">' . esc_html(number_format_i18n($stats_airlines)) . '</span><span class="pr-stat-label">Airlines</span></div>';
    $content .= '<div class="pr-stat"><span class="pr-stat-num">' . esc_html(number_format_i18n($stats_destinations)) . '</span><span class="pr-stat-label">Destinations</span></div>';
    $content .= '</div></div>';

    $content .= '<div class="pr-prose">';
    $content .= '<h2>About ' . esc_html($airport) . ' Airport Flight Schedules</h2>';
    $content .= wp_kses_post($about_html);
    $content .= '</div>';

    $content .= '<div class="pr-section-label">Airlines Serving ' . esc_html($airport) . '</div>';
    $content .= '<div class="pr-chip-row">' . $airline_html . '</div>';

    $content .= '<div class="pr-section-label">Top Routes from ' . esc_html($airport) . '</div>';
    $content .= '<div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>Destination</th><th>Code</th><th>Flights</th><th>Airlines</th></tr></thead><tbody>' . $routes_html . '</tbody></table></div>';

    $content .= '<div class="pr-cta-block">';
    $content .= '<h3>Search Flights &amp; Schedules at ' . esc_html($airport) . '</h3>';
    $content .= '<p>Search all ' . esc_html(number_format_i18n($stats_flights)) . ' scheduled departures from ' . esc_html($airport) . ' by airline, route, and date using Passrider&rsquo;s free schedule tool. Or view a live departure board for ' . esc_html($airport_name) . ' via the Airport FIDS.</p>';
    $content .= '<div class="pr-btn-row">';
    $content .= '<a class="pr-btn pr-btn-primary" href="https://www.passrider.com/reservations/advanced-search/">Search Flight Schedules &rarr;</a>';
    $content .= '<a class="pr-btn pr-btn-secondary" href="https://www.passrider.com/fids/">Live Airport FIDS &rarr;</a>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    return $content;
}

function airport_fid_generate_about_text($dataset, $stats_flights, $stats_airlines, $stats_destinations) {
    $airport = strtoupper((string) ($dataset['airport'] ?? ''));
    $airport_name = (string) ($dataset['airport_name'] ?? $airport);
    $flight_date = (string) ($dataset['flight_date'] ?? '');
    $default_html = '<p>This page is auto-generated from Airport FID cached requests and refreshed weekly. It summarizes flights for ' . esc_html($airport_name) . ' and provides schedule insights.</p>';

    $settings = airport_fid_get_settings();
    if ((int) ($settings['airport_ai_enabled'] ?? 0) !== 1) {
        return $default_html;
    }

    $provider = (string) ($settings['airport_ai_provider'] ?? 'openai');
    $model = $provider === 'claude'
        ? (string) ($settings['airport_ai_claude_model'] ?? 'claude-sonnet-4-6')
        : (string) ($settings['airport_ai_openai_model'] ?? 'gpt-4o-mini');

    $cache_key = 'airport_fid_ai_about_' . md5($airport . '|' . $flight_date . '|' . $provider . '|' . $model . '|' . $stats_flights . '|' . $stats_airlines . '|' . $stats_destinations);
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $flights = isset($dataset['flights']) && is_array($dataset['flights']) ? $dataset['flights'] : array();
    $dest_counts = array();
    $airline_counts = array();
    foreach ($flights as $flight) {
        if (!is_array($flight)) {
            continue;
        }
        $dest = strtoupper((string) ($flight['destination'] ?? ''));
        if ($dest !== '') {
            if (!isset($dest_counts[$dest])) {
                $dest_counts[$dest] = 0;
            }
            $dest_counts[$dest]++;
        }
        $airline = strtoupper((string) ($flight['airline_code'] ?? $flight['airline'] ?? ''));
        if ($airline !== '') {
            if (!isset($airline_counts[$airline])) {
                $airline_counts[$airline] = 0;
            }
            $airline_counts[$airline]++;
        }
    }
    arsort($dest_counts);
    arsort($airline_counts);
    $top_dest = array_slice(array_keys($dest_counts), 0, 8);
    $top_airlines = array_slice(array_keys($airline_counts), 0, 6);

    $prompt = "Write 2 concise HTML paragraphs for an airport schedule page.\n"
        . "Airport code: {$airport}\n"
        . "Airport name: {$airport_name}\n"
        . "Flights: {$stats_flights}\n"
        . "Airlines: {$stats_airlines}\n"
        . "Destinations: {$stats_destinations}\n"
        . "Top destinations: " . implode(', ', $top_dest) . "\n"
        . "Top airlines: " . implode(', ', $top_airlines) . "\n"
        . "Rules: factual tone, no markdown, no links, return valid HTML only with exactly two <p>...</p> blocks.";

    $generated_html = '';
    if ($provider === 'claude') {
        $generated_html = airport_fid_generate_about_with_claude($prompt, $settings);
    } else {
        $generated_html = airport_fid_generate_about_with_openai($prompt, $settings);
    }

    if (!is_string($generated_html) || trim($generated_html) === '') {
        return $default_html;
    }

    $clean_html = wp_kses($generated_html, array(
        'p' => array(),
        'strong' => array(),
        'em' => array(),
        'br' => array(),
    ));
    if (strpos($clean_html, '<p') === false) {
        $plain = trim(wp_strip_all_tags($generated_html));
        if ($plain !== '') {
            $chunks = preg_split('/\n{2,}/', $plain);
            if (is_array($chunks) && !empty($chunks)) {
                $rebuilt = '';
                $count = 0;
                foreach ($chunks as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk === '') {
                        continue;
                    }
                    $rebuilt .= '<p>' . esc_html($chunk) . '</p>';
                    $count++;
                    if ($count >= 2) {
                        break;
                    }
                }
                if ($rebuilt !== '') {
                    $clean_html = $rebuilt;
                }
            }
        }
    }
    if (trim($clean_html) === '') {
        return $default_html;
    }
    set_transient($cache_key, $clean_html, WEEK_IN_SECONDS);
    return $clean_html;
}

function airport_fid_generate_about_with_openai($prompt, $settings) {
    $api_key = trim((string) ($settings['airport_ai_openai_key'] ?? ''));
    if ($api_key === '') {
        return '';
    }
    $model = trim((string) ($settings['airport_ai_openai_model'] ?? 'gpt-4o-mini'));
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
        'timeout' => 25,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode(array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => 'You write concise airport schedule page copy in HTML.'),
                array('role' => 'user', 'content' => $prompt),
            ),
            'temperature' => 0.4,
        )),
    ));

    if (is_wp_error($response)) {
        return '';
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return '';
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return '';
    }
    return (string) ($body['choices'][0]['message']['content'] ?? '');
}

function airport_fid_generate_about_with_claude($prompt, $settings) {
    $api_key = trim((string) ($settings['airport_ai_claude_key'] ?? ''));
    if ($api_key === '') {
        return '';
    }
    $model = trim((string) ($settings['airport_ai_claude_model'] ?? 'claude-sonnet-4-6'));
    if ($model === '') {
        $model = 'claude-sonnet-4-6';
    }

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'timeout' => 25,
        'headers' => array(
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ),
        'body' => wp_json_encode(array(
            'model' => $model,
            'max_tokens' => 500,
            'temperature' => 0.4,
            'system' => 'You write concise airport schedule page copy in HTML only.',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $prompt,
                        ),
                    ),
                ),
            ),
        )),
    ));

    if (is_wp_error($response)) {
        return '';
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return '';
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return '';
    }
    if (isset($body['content']) && is_array($body['content'])) {
        foreach ($body['content'] as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text' && !empty($part['text'])) {
                return (string) $part['text'];
            }
        }
    }
    return '';
}

function airport_fid_generate_and_attach_featured_image($post_id, $dataset) {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        return;
    }
    if (empty($post_id) || !is_array($dataset)) {
        return;
    }

    $airport = strtoupper((string) ($dataset['airport'] ?? ''));
    $airport_name = (string) ($dataset['airport_name'] ?? $airport);
    if ($airport === '') {
        return;
    }

    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        return;
    }
    $dir = trailingslashit($upload['basedir']) . 'airport-fid';
    if (!wp_mkdir_p($dir)) {
        return;
    }

    $flight_date = (string) ($dataset['flight_date'] ?? '');
    $updated_at = (string) ($dataset['updated_at'] ?? '');
    $flight_count = isset($dataset['flights']) && is_array($dataset['flights']) ? count($dataset['flights']) : 0;
    $revision = substr(md5($airport . '|' . $flight_date . '|' . $updated_at . '|' . $flight_count), 0, 10);
    $filename = sanitize_title(strtolower($airport) . '-airport-flight-schedules') . '-featured-' . $revision . '.png';
    $file_path = trailingslashit($dir) . $filename;
    $rendered = airport_fid_render_featured_image_png($file_path, $airport, $airport_name, $dataset);
    if (!$rendered) {
        return;
    }

    $mime = 'image/png';
    $attachment_id = (int) get_post_meta($post_id, AIRPORT_FID_PAGE_META_FEATURED, true);
    if ($attachment_id > 0 && get_post($attachment_id)) {
        update_attached_file($attachment_id, $file_path);
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_mime_type' => $mime,
            'post_title' => $airport . ' Airport Flight Schedules',
            'post_status' => 'inherit',
        ));
    } else {
        $attachment = array(
            'post_mime_type' => $mime,
            'post_title' => $airport . ' Airport Flight Schedules',
            'post_content' => '',
            'post_status' => 'inherit',
        );
        $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id, true);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            return;
        }
        update_post_meta($post_id, AIRPORT_FID_PAGE_META_FEATURED, (int) $attachment_id);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
    if (!is_wp_error($metadata) && !empty($metadata)) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    set_post_thumbnail($post_id, $attachment_id);
}

function airport_fid_get_banner_font() {
    $candidates = array(
        plugin_dir_path(__FILE__) . 'assets/fonts/BarlowCondensed-Bold.ttf',
        plugin_dir_path(__FILE__) . 'assets/fonts/Barlow-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
        '/Library/Fonts/Arial.ttf',
    );
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return '';
}

function airport_fid_draw_banner_text($img, $font, $size, $x, $y, $color, $text, $fallback_font = 4) {
    $text = (string) $text;
    $font = (string) $font;
    if ($text === '') {
        return false;
    }

    if ($font !== '' && function_exists('imagettftext')) {
        $result = @imagettftext($img, (float) $size, 0, (int) $x, (int) $y, $color, $font, $text);
        if ($result !== false) {
            return true;
        }
    }

    imagestring($img, (int) $fallback_font, (int) $x, max(0, (int) $y - 12), $text, $color);
    return false;
}

function airport_fid_get_top_flights_for_banner($flights, $limit = 10) {
    $rows = array();
    if (!is_array($flights)) {
        return $rows;
    }
    foreach ($flights as $flight) {
        if (!is_array($flight)) {
            continue;
        }
        $dest_code = strtoupper((string) ($flight['destination'] ?? ''));
        $dest_name = strtoupper((string) ($flight['destination_name'] ?? $dest_code));
        $dest_name = preg_replace('/\s+AIRPORT$/i', '', $dest_name);
        $dep = (string) ($flight['departure_time'] ?? '--:--');
        $airline = strtoupper((string) ($flight['airline_code'] ?? '--'));
        $status = strtoupper((string) ($flight['status'] ?? 'SCHEDULED'));
        if ($status === 'IN AIR' || $status === 'ARRIVING') {
            $status = 'ON TIME';
        }
        $terminal = strtoupper((string) ($flight['terminal'] ?? '--'));
        $gate = '--';
        if ($terminal !== '' && $terminal !== '--') {
            if (strpos($terminal, '->') !== false) {
                $parts = array_map('trim', explode('->', $terminal));
                $gate = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($parts[0] ?? '')));
            } else {
                $gate = strtoupper(preg_replace('/[^A-Z0-9]/', '', $terminal));
            }
            if ($gate === '') {
                $gate = '--';
            }
        }
        $rows[] = array(
            'destination' => trim($dest_name . ' ' . $dest_code),
            'dep' => $dep,
            'al' => $airline,
            'status' => $status,
            'gate' => $gate,
        );
        if (count($rows) >= $limit) {
            break;
        }
    }
    return $rows;
}

function airport_fid_render_featured_image_png($file_path, $airport, $airport_name, $dataset) {
    $width = 1200;
    $height = 628;
    $img = imagecreatetruecolor($width, $height);
    if (!$img) {
        return false;
    }

    $bg = imagecolorallocate($img, 8, 24, 49);
    $bg2 = imagecolorallocate($img, 10, 34, 67);
    $grid = imagecolorallocatealpha($img, 51, 117, 191, 104);
    $white = imagecolorallocate($img, 231, 237, 247);
    $muted = imagecolorallocate($img, 151, 169, 192);
    $cyan = imagecolorallocate($img, 61, 177, 234);
    $amber = imagecolorallocate($img, 245, 166, 35);
    $green = imagecolorallocate($img, 55, 211, 138);

    imagefilledrectangle($img, 0, 0, $width, $height, $bg);
    imagefilledrectangle($img, 0, 0, $width, 44, $bg2);
    imagefilledrectangle($img, 0, 44, 8, $height - 72, $amber);
    imagefilledrectangle($img, 0, $height - 72, $width, $height, $bg2);

    for ($x = 0; $x < $width; $x += 60) {
        imageline($img, $x, 44, $x, $height - 72, $grid);
    }
    for ($y = 44; $y < $height - 72; $y += 40) {
        imageline($img, 0, $y, $width, $y, $grid);
    }

    $font = airport_fid_get_banner_font();
    $can_ttf = ($font !== '' && function_exists('imagettftext'));
    if ($can_ttf) {
        airport_fid_draw_banner_text($img, $font, 16, 20, 28, $cyan, 'PASSRIDER.COM', 5);
        airport_fid_draw_banner_text($img, $font, 16, $width - 58, 28, $amber, strtoupper($airport), 5);

        $airport_name_u = strtoupper((string) $airport_name);
        $airport_name_u = preg_replace('/\s+AIRPORT$/', '', $airport_name_u);
        if (strlen($airport_name_u) > 28) {
            $airport_name_u = wordwrap($airport_name_u, 28, "\n", true);
        }

        airport_fid_draw_banner_text($img, $font, 98, 30, 200, $white, strtoupper($airport), 5);
        imageline($img, 30, 186, 178, 186, $amber);
        airport_fid_draw_banner_text($img, $font, 74, 30, 426, $white, 'FLIGHT SCHEDULE', 5);
        airport_fid_draw_banner_text($img, $font, 74, 30, 520, $amber, '& DEPARTURE BOARD', 5);

        $name_lines = explode("\n", $airport_name_u);
        $line_y = 260;
        foreach ($name_lines as $line) {
            airport_fid_draw_banner_text($img, $font, 30, 30, $line_y, $muted, $line, 5);
            $line_y += 52;
        }

        airport_fid_draw_banner_text($img, $font, 13, 620, 88, $muted, 'DESTINATION', 4);
        airport_fid_draw_banner_text($img, $font, 13, 768, 88, $muted, 'DEP', 4);
        airport_fid_draw_banner_text($img, $font, 13, 826, 88, $muted, 'AL', 4);
        airport_fid_draw_banner_text($img, $font, 13, 878, 88, $muted, 'STATUS', 4);
        airport_fid_draw_banner_text($img, $font, 13, 962, 88, $muted, 'GATE', 4);

        // Guaranteed visibility fallback: draw core headings with bitmap text too.
        imagestring($img, 5, 20, 14, 'PASSRIDER.COM', $cyan);
        imagestring($img, 5, $width - 44, 14, strtoupper($airport), $amber);
        imagestring($img, 5, 30, 132, 'FLIGHT SCHEDULE', $white);
        imagestring($img, 5, 30, 162, '& DEPARTURE BOARD', $amber);
    } else {
        imagestring($img, 5, 20, 14, 'PASSRIDER.COM', $cyan);
        imagestring($img, 5, $width - 44, 14, strtoupper($airport), $amber);
        imagestring($img, 5, 30, 76, strtoupper($airport), $white);
        imagestring($img, 5, 30, 132, 'FLIGHT SCHEDULE', $white);
        imagestring($img, 5, 30, 162, '& DEPARTURE BOARD', $amber);
        $airport_name_u = strtoupper((string) $airport_name);
        $airport_name_u = preg_replace('/\s+AIRPORT$/', '', $airport_name_u);
        $airport_name_u = wordwrap($airport_name_u, 26, "\n", true);
        $name_lines = explode("\n", (string) $airport_name_u);
        $line_y = 214;
        foreach ($name_lines as $line) {
            imagestring($img, 5, 30, $line_y, trim($line), $muted);
            $line_y += 24;
        }
        imagestring($img, 4, 620, 76, 'DESTINATION', $muted);
        imagestring($img, 4, 768, 76, 'DEP', $muted);
        imagestring($img, 4, 826, 76, 'AL', $muted);
        imagestring($img, 4, 878, 76, 'STATUS', $muted);
        imagestring($img, 4, 962, 76, 'GATE', $muted);
    }

    $rows = airport_fid_get_top_flights_for_banner($dataset['flights'] ?? array(), 10);
    $row_y = 104;
    foreach ($rows as $index => $row) {
        $shade = ($index % 2 === 0) ? imagecolorallocatealpha($img, 19, 41, 74, 45) : imagecolorallocatealpha($img, 12, 27, 53, 35);
        imagefilledrectangle($img, 620, $row_y - 22, 1120, $row_y + 18, $shade);
        if ($can_ttf) {
            $status_color = ($row['status'] === 'ON TIME') ? $green : $muted;
            airport_fid_draw_banner_text($img, $font, 16, 628, $row_y + 8, $white, $row['destination'], 4);
            airport_fid_draw_banner_text($img, $font, 16, 768, $row_y + 8, $amber, $row['dep'], 4);
            airport_fid_draw_banner_text($img, $font, 16, 826, $row_y + 8, $cyan, $row['al'], 4);
            airport_fid_draw_banner_text($img, $font, 16, 878, $row_y + 8, $status_color, $row['status'], 4);
            airport_fid_draw_banner_text($img, $font, 16, 962, $row_y + 8, $muted, $row['gate'], 4);
        } else {
            $status_color = ($row['status'] === 'ON TIME') ? $green : $muted;
            imagestring($img, 4, 628, $row_y - 12, substr((string) $row['destination'], 0, 18), $white);
            imagestring($img, 4, 768, $row_y - 12, (string) $row['dep'], $amber);
            imagestring($img, 4, 826, $row_y - 12, (string) $row['al'], $cyan);
            imagestring($img, 4, 878, $row_y - 12, substr((string) $row['status'], 0, 9), $status_color);
            imagestring($img, 4, 962, $row_y - 12, (string) $row['gate'], $muted);
        }
        $row_y += 48;
    }

    $airlines = array();
    $destinations = array();
    foreach (($dataset['flights'] ?? array()) as $flight) {
        if (!is_array($flight)) {
            continue;
        }
        $al_key = strtoupper((string) ($flight['airline_code'] ?? $flight['airline'] ?? ''));
        if ($al_key !== '') {
            $airlines[$al_key] = true;
        }
        $d = strtoupper((string) ($flight['destination'] ?? ''));
        if ($d !== '') {
            $destinations[$d] = true;
        }
    }

    $stats = array(
        number_format_i18n(count($dataset['flights'] ?? array())) . "\nFLIGHTS TODAY",
        number_format_i18n(count($airlines)) . "\nAIRLINES",
        number_format_i18n(count($destinations)) . "\nDESTINATIONS",
        '#1' . "\nBUSIEST AIRPORT",
    );
    $stat_x = 80;
    foreach ($stats as $i => $label) {
        $parts = explode("\n", $label);
        if ($can_ttf) {
            airport_fid_draw_banner_text($img, $font, 30, $stat_x, $height - 42, $amber, $parts[0], 5);
            airport_fid_draw_banner_text($img, $font, 12, $stat_x, $height - 22, $muted, $parts[1], 2);
        } else {
            imagestring($img, 5, $stat_x, $height - 58, $parts[0], $amber);
            imagestring($img, 2, $stat_x, $height - 30, $parts[1], $muted);
        }
        if ($i < count($stats) - 1) {
            imageline($img, $stat_x + 140, $height - 64, $stat_x + 140, $height - 8, $grid);
        }
        $stat_x += 240;
    }

    $ok = imagepng($img, $file_path);
    imagedestroy($img);
    return (bool) $ok;
}

function airport_fid_admin_text_field($label, $key, $value, $attrs = array()) {
    $type = isset($attrs['type']) ? (string) $attrs['type'] : 'text';
    unset($attrs['type']);
    echo '<label class="airport-fid-admin-field"><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr(AIRPORT_FID_OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"';
    foreach ($attrs as $attr_key => $attr_value) {
        echo ' ' . esc_attr($attr_key) . '="' . esc_attr($attr_value) . '"';
    }
    echo ' /></label>';
}

function airport_fid_admin_number_field($label, $key, $value, $min, $max) {
    echo '<label class="airport-fid-admin-field"><span>' . esc_html($label) . '</span><input type="number" name="' . esc_attr(AIRPORT_FID_OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" /></label>';
}

function airport_fid_admin_color_field($label, $key, $value) {
    echo '<label class="airport-fid-admin-field"><span>' . esc_html($label) . '</span><input type="color" name="' . esc_attr(AIRPORT_FID_OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" /></label>';
}

function airport_fid_admin_checkbox_field($label, $key, $checked_value) {
    echo '<label class="airport-fid-admin-field airport-fid-admin-checkbox"><input type="checkbox" name="' . esc_attr(AIRPORT_FID_OPTION_KEY) . '[' . esc_attr($key) . ']" value="1" ' . checked(1, $checked_value, false) . ' /><span>' . esc_html($label) . '</span></label>';
}

function airport_fid_admin_select_field($label, $key, $value, $options) {
    echo '<label class="airport-fid-admin-field"><span>' . esc_html($label) . '</span><select name="' . esc_attr(AIRPORT_FID_OPTION_KEY) . '[' . esc_attr($key) . ']">';
    foreach ($options as $option_value => $option_label) {
        echo '<option value="' . esc_attr($option_value) . '"' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
    }
    echo '</select></label>';
}

function airport_fid_get_site_identity() {
    $settings = airport_fid_get_settings();
    $site_url = home_url('/');
    $site_label = trim((string) ($settings['analytics_site_label'] ?? ''));
    if ($site_label === '') {
        $site_label = get_bloginfo('name');
    }

    return array(
        'site_url' => untrailingslashit((string) $site_url),
        'site_label' => $site_label !== '' ? $site_label : parse_url((string) $site_url, PHP_URL_HOST),
    );
}

function airport_fid_get_remote_analytics_endpoint($base_url) {
    $base_url = trim((string) $base_url);
    if ($base_url === '') {
        return '';
    }
    if (strpos($base_url, '/wp-json/') !== false) {
        return untrailingslashit($base_url);
    }
    return untrailingslashit($base_url) . '/wp-json/airport-fid/v1/remote-search-log';
}

function airport_fid_send_remote_search_log($payload) {
    $settings = airport_fid_get_settings();
    if ((int) ($settings['analytics_remote_enabled'] ?? 0) !== 1) {
        return false;
    }

    $endpoint = airport_fid_get_remote_analytics_endpoint((string) ($settings['analytics_remote_url'] ?? ''));
    $key = trim((string) ($settings['analytics_remote_key'] ?? ''));
    if ($endpoint === '' || $key === '') {
        return false;
    }

    $response = wp_remote_post($endpoint, array(
        'timeout' => 8,
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Airport-FID-Key' => $key,
        ),
        'body' => wp_json_encode($payload),
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    return $code >= 200 && $code < 300;
}

function airport_fid_log_search($data) {
    global $wpdb;
    $table = airport_fid_get_search_log_table();

    $airport = strtoupper(sanitize_text_field((string) ($data['airport'] ?? '')));
    $flight_date = sanitize_text_field((string) ($data['date'] ?? ''));
    $sort = sanitize_text_field((string) ($data['sort'] ?? 'departure_time'));
    $source = sanitize_text_field((string) ($data['source'] ?? 'manual'));
    $raw_input = sanitize_text_field((string) ($data['raw_input'] ?? ''));
    $selected_airport = sanitize_text_field((string) ($data['selected_airport'] ?? ''));
    $cache_id = isset($data['cache_id']) ? (int) $data['cache_id'] : 0;
    $created_at = sanitize_text_field((string) ($data['created_at'] ?? current_time('mysql')));
    $skip_remote = !empty($data['skip_remote']);
    $identity = airport_fid_get_site_identity();
    $site_url = isset($data['site_url']) ? untrailingslashit(esc_url_raw((string) $data['site_url'])) : $identity['site_url'];
    $site_label = isset($data['site_label']) ? sanitize_text_field((string) $data['site_label']) : $identity['site_label'];

    if ($airport === '' || !preg_match('/^\d{8}$/', $flight_date)) {
        return 0;
    }
    if ($cache_id <= 0) {
        $cache_id = airport_fid_get_cache_id($airport, $flight_date, $sort);
    }

    $wpdb->insert($table, array(
        'cache_id' => $cache_id > 0 ? $cache_id : null,
        'airport' => $airport,
        'flight_date' => $flight_date,
        'sort' => $sort,
        'source' => $source ?: 'manual',
        'raw_input' => $raw_input,
        'selected_airport' => $selected_airport,
        'site_url' => $site_url,
        'site_label' => $site_label,
        'created_at' => $created_at,
    ), array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));

    $insert_id = $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
    if ($insert_id > 0 && !$skip_remote) {
        airport_fid_send_remote_search_log(array(
            'airport' => $airport,
            'date' => $flight_date,
            'sort' => $sort,
            'source' => $source ?: 'manual',
            'raw_input' => $raw_input,
            'selected_airport' => $selected_airport,
            'site_url' => $site_url,
            'site_label' => $site_label,
            'created_at' => $created_at,
        ));
    }

    return $insert_id;
}

function airport_fid_get_search_analytics($site_url = '', $filters = array()) {
    global $wpdb;
    $table = airport_fid_get_search_log_table();
    $site_url = untrailingslashit((string) $site_url);
    $where_sql = ' WHERE 1=1';
    $where_args = array();

    if ($site_url !== '') {
        $where_sql .= ' AND site_url = %s';
        $where_args[] = $site_url;
    }

    $filter_site = untrailingslashit((string) ($filters['site'] ?? ''));
    $filter_airport = strtoupper(sanitize_text_field((string) ($filters['airport'] ?? '')));
    $filter_date = sanitize_text_field((string) ($filters['date'] ?? ''));

    if ($filter_site !== '') {
        $where_sql .= ' AND site_url = %s';
        $where_args[] = $filter_site;
    }
    if ($filter_airport !== '') {
        $where_sql .= ' AND airport = %s';
        $where_args[] = $filter_airport;
    }
    if ($filter_date !== '' && preg_match('/^\d{8}$/', $filter_date)) {
        $where_sql .= ' AND flight_date = %s';
        $where_args[] = $filter_date;
    }

    $today_sql = "SELECT COUNT(*) FROM {$table}";
    $today_sql .= $where_sql . ' AND DATE(created_at) = %s';

    $total_sql = "SELECT COUNT(*) FROM {$table}" . $where_sql;
    $top_airports_sql = "SELECT airport, COUNT(*) AS searches FROM {$table}" . $where_sql . " GROUP BY airport ORDER BY searches DESC, airport ASC LIMIT 5";
    $top_sources_sql = "SELECT source, COUNT(*) AS searches FROM {$table}" . $where_sql . " GROUP BY source ORDER BY searches DESC, source ASC LIMIT 5";
    $top_sites_sql = "SELECT COALESCE(NULLIF(site_label, ''), NULLIF(site_url, ''), 'Unknown site') AS site_label, site_url, COUNT(*) AS searches FROM {$table}" . $where_sql . " GROUP BY site_label, site_url ORDER BY searches DESC, site_label ASC LIMIT 5";
    $recent_sql = "SELECT id, cache_id, airport, flight_date, sort, source, raw_input, selected_airport, site_url, site_label, created_at FROM {$table}" . $where_sql . " ORDER BY created_at DESC LIMIT 100";

    $summary = array(
        'filters' => array(
            'site' => $filter_site,
            'airport' => $filter_airport,
            'date' => $filter_date,
        ),
        'total' => (int) $wpdb->get_var($wpdb->prepare($total_sql, $where_args)),
        'today' => (int) $wpdb->get_var($wpdb->prepare($today_sql, array_merge($where_args, array(current_time('Y-m-d'))))),
        'top_airports' => $wpdb->get_results($wpdb->prepare($top_airports_sql, $where_args), ARRAY_A),
        'top_sources' => $wpdb->get_results($wpdb->prepare($top_sources_sql, $where_args), ARRAY_A),
        'top_sites' => $wpdb->get_results($wpdb->prepare($top_sites_sql, $where_args), ARRAY_A),
        'recent' => $wpdb->get_results($wpdb->prepare($recent_sql, $where_args), ARRAY_A),
    );

    return $summary;
}

function airport_fid_render_analytics_section() {
    $identity = airport_fid_get_site_identity();
    $analytics = airport_fid_get_search_analytics($identity['site_url']);
    echo '<section class="airport-fid-admin-cache-section">';
    echo '<h2>Analytics</h2>';
    echo '<p>Showing search analytics for this website only: <strong>' . esc_html($identity['site_label']) . '</strong>.</p>';
    if (!airport_fid_is_search_backfill_done()) {
        echo '<div class="airport-fid-admin-notice is-success airport-fid-admin-notice-flex">';
        echo '<span>Backfill existing cache rows into Analytics once so earlier cached searches appear in reports.</span>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="airport_fid_backfill_search_log" />';
        wp_nonce_field('airport_fid_backfill_search_log', 'airport_fid_backfill_nonce');
        echo '<button type="submit" class="button button-primary">Backfill Analytics From Cache</button>';
        echo '</form>';
        echo '</div>';
    }
    echo '<div class="airport-fid-analytics-grid">';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html(number_format_i18n((int) $analytics['total'])) . '</strong><span>Total Searches</span></div>';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html(number_format_i18n((int) $analytics['today'])) . '</strong><span>Searches Today</span></div>';
    $top_airport = !empty($analytics['top_airports'][0]['airport']) ? $analytics['top_airports'][0]['airport'] : '--';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html($top_airport) . '</strong><span>Top Airport</span></div>';
    echo '</div>';

    echo '<div class="airport-fid-admin-grid" style="margin-top:12px;">';
    echo '<div class="airport-fid-admin-table-wrap">';
    echo '<table class="airport-fid-admin-table">';
    echo '<thead><tr><th>Top Airports</th><th>Searches</th></tr></thead><tbody>';
    if (!empty($analytics['top_airports'])) {
        foreach ($analytics['top_airports'] as $row) {
            echo '<tr><td>' . esc_html((string) $row['airport']) . '</td><td>' . esc_html(number_format_i18n((int) $row['searches'])) . '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="2">No search data yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';

    echo '</div>';

    echo '<div class="airport-fid-admin-table-wrap" style="margin-top:12px;">';
    echo '<table class="airport-fid-admin-table">';
    echo '<thead><tr><th>When</th><th>Airport</th><th>Date</th><th>Sort</th><th>Source</th><th>Raw Input</th><th>Selected Airport</th><th>Cache ID</th></tr></thead><tbody>';
    if (!empty($analytics['recent'])) {
        foreach ($analytics['recent'] as $row) {
            $date_display = preg_match('/^\d{8}$/', (string) $row['flight_date'])
                ? substr((string) $row['flight_date'], 0, 4) . '-' . substr((string) $row['flight_date'], 4, 2) . '-' . substr((string) $row['flight_date'], 6, 2)
                : (string) $row['flight_date'];
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
            echo '<td>' . esc_html((string) $row['airport']) . '</td>';
            echo '<td>' . esc_html($date_display) . '</td>';
            echo '<td>' . esc_html((string) $row['sort']) . '</td>';
            echo '<td>' . esc_html((string) $row['source']) . '</td>';
            echo '<td>' . esc_html((string) $row['raw_input']) . '</td>';
            echo '<td>' . esc_html((string) $row['selected_airport']) . '</td>';
            echo '<td>' . esc_html((string) ((int) $row['cache_id'])) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="8">No searches logged yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</section>';
}

function airport_fid_render_hub_analytics_section() {
    $analytics = airport_fid_get_search_analytics();
    echo '<section class="airport-fid-admin-cache-section">';
    echo '<h2>Hub Analytics</h2>';
    echo '<p>Showing combined analytics from all connected websites grouped by site.</p>';
    echo '<div class="airport-fid-analytics-grid">';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html(number_format_i18n((int) $analytics['total'])) . '</strong><span>Total Searches</span></div>';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html(number_format_i18n((int) $analytics['today'])) . '</strong><span>Searches Today</span></div>';
    $top_site = !empty($analytics['top_sites'][0]['site_label']) ? $analytics['top_sites'][0]['site_label'] : '--';
    $top_airport = !empty($analytics['top_airports'][0]['airport']) ? $analytics['top_airports'][0]['airport'] : '--';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html($top_site) . '</strong><span>Top Site</span></div>';
    echo '<div class="airport-fid-analytics-card"><strong>' . esc_html($top_airport) . '</strong><span>Top Airport</span></div>';
    echo '</div>';

    echo '<div class="airport-fid-admin-grid" style="margin-top:12px;">';
    echo '<div class="airport-fid-admin-table-wrap">';
    echo '<table class="airport-fid-admin-table">';
    echo '<thead><tr><th>Website</th><th>Searches</th></tr></thead><tbody>';
    if (!empty($analytics['top_sites'])) {
        foreach ($analytics['top_sites'] as $row) {
            $site_label = (string) ($row['site_label'] ?? '');
            if ($site_label === '') {
                $site_label = (string) ($row['site_url'] ?? 'Unknown site');
            }
            echo '<tr><td>' . esc_html($site_label) . '</td><td>' . esc_html(number_format_i18n((int) $row['searches'])) . '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="2">No hub data yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="airport-fid-admin-table-wrap">';
    echo '<table class="airport-fid-admin-table">';
    echo '<thead><tr><th>Top Airports</th><th>Searches</th></tr></thead><tbody>';
    if (!empty($analytics['top_airports'])) {
        foreach ($analytics['top_airports'] as $row) {
            echo '<tr><td>' . esc_html((string) $row['airport']) . '</td><td>' . esc_html(number_format_i18n((int) $row['searches'])) . '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="2">No hub airport data yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';

    echo '<div class="airport-fid-admin-filter-bar airport-fid-hub-table-filters" style="margin-top:12px;">';
    echo '<label class="airport-fid-admin-field"><span>Site</span><select class="airport-fid-hub-filter-site"><option value="">All</option></select></label>';
    echo '<label class="airport-fid-admin-field"><span>Airport</span><select class="airport-fid-hub-filter-airport"><option value="">All</option></select></label>';
    echo '<label class="airport-fid-admin-field"><span>Date</span><input type="date" class="airport-fid-hub-filter-date" /></label>';
    echo '<div class="airport-fid-admin-filter-actions"><button type="button" class="button airport-fid-hub-filter-reset">Reset</button></div>';
    echo '</div>';
    echo '<div class="airport-fid-admin-table-wrap airport-fid-hub-results-wrap" style="margin-top:12px;">';
    echo '<table class="airport-fid-admin-table airport-fid-hub-results-table">';
    echo '<thead><tr><th>When</th><th>Site</th><th>Airport</th><th>Date</th><th>Sort</th><th>Source</th><th>Raw Input</th><th>Selected Airport</th></tr></thead><tbody>';
    if (!empty($analytics['recent'])) {
        foreach ($analytics['recent'] as $row) {
            $date_display = preg_match('/^\d{8}$/', (string) $row['flight_date'])
                ? substr((string) $row['flight_date'], 0, 4) . '-' . substr((string) $row['flight_date'], 4, 2) . '-' . substr((string) $row['flight_date'], 6, 2)
                : (string) $row['flight_date'];
            $site_label = (string) ($row['site_label'] ?? '');
            if ($site_label === '') {
                $site_label = (string) ($row['site_url'] ?? 'Unknown site');
            }
            echo '<tr data-site="' . esc_attr($site_label) . '" data-airport="' . esc_attr((string) $row['airport']) . '" data-date="' . esc_attr($date_display) . '">';
            echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
            echo '<td>' . esc_html($site_label) . '</td>';
            echo '<td>' . esc_html((string) $row['airport']) . '</td>';
            echo '<td>' . esc_html($date_display) . '</td>';
            echo '<td>' . esc_html((string) $row['sort']) . '</td>';
            echo '<td>' . esc_html((string) $row['source']) . '</td>';
            echo '<td>' . esc_html((string) $row['raw_input']) . '</td>';
            echo '<td>' . esc_html((string) $row['selected_airport']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="8">No hub searches logged yet.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '<div class="airport-fid-hub-pagination" data-page-size="25"></div>';
    echo '</section>';
}

function airport_fid_register_assets() {
    wp_register_style(
        'airport-fid-style',
        plugins_url('assets/css/fid.css', __FILE__),
        array(),
        AIRPORT_FID_VERSION
    );
    wp_register_style(
        'airport-fid-airport-page-style',
        plugins_url('assets/css/airport-page.css', __FILE__),
        array(),
        AIRPORT_FID_VERSION
    );
    wp_register_script(
        'airport-fid-script',
        plugins_url('assets/js/fid.js', __FILE__),
        array(),
        AIRPORT_FID_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'airport_fid_register_assets');

function airport_fid_enqueue_airport_page_style() {
    if (!is_singular('page')) {
        return;
    }
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }
    $is_generated = get_post_meta($post_id, AIRPORT_FID_PAGE_META_FLAG, true);
    if ((string) $is_generated !== '1') {
        return;
    }
    wp_enqueue_style('airport-fid-airport-page-style');
}
add_action('wp_enqueue_scripts', 'airport_fid_enqueue_airport_page_style', 20);

function airport_fid_register_admin_assets($hook) {
    if ($hook !== 'toplevel_page_airport-fid-settings') {
        return;
    }
    wp_enqueue_style(
        'airport-fid-admin-style',
        plugins_url('assets/css/admin-settings.css', __FILE__),
        array(),
        AIRPORT_FID_VERSION
    );
    wp_enqueue_script(
        'airport-fid-admin-script',
        plugins_url('assets/js/admin-settings.js', __FILE__),
        array(),
        AIRPORT_FID_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'airport_fid_register_admin_assets');

function airport_fid_frontend_inline_css($settings) {
    $css = '.airport-fid-board{';
    $css .= '--fid-accent:' . esc_attr($settings['accent_color']) . ';';
    $css .= '--fid-text-dark:' . esc_attr($settings['text_dark']) . ';';
    $css .= '--fid-text-light:' . esc_attr($settings['text_light']) . ';';
    $css .= '--fid-bg-dark:' . esc_attr($settings['background_dark']) . ';';
    $css .= '--fid-bg-light:' . esc_attr($settings['background_light']) . ';';
    $css .= '--fid-row-dark-odd:' . esc_attr($settings['row_dark_odd']) . ';';
    $css .= '--fid-row-dark-even:' . esc_attr($settings['row_dark_even']) . ';';
    $css .= '--fid-row-light-odd:' . esc_attr($settings['row_light_odd']) . ';';
    $css .= '--fid-row-light-even:' . esc_attr($settings['row_light_even']) . ';';
    $css .= '--fid-header-size:' . (int) $settings['header_font_size'] . 'px;';
    $css .= '--fid-field-size:' . (int) $settings['field_font_size'] . 'px;';
    $css .= '--fid-button-size:' . (int) $settings['button_font_size'] . 'px;';
    $css .= '--fid-row-main-size:' . (int) $settings['result_main_font_size'] . 'px;';
    $css .= '--fid-row-sub-size:' . (int) $settings['result_sub_font_size'] . 'px;';
    $css .= '--fid-expanded-label-size:' . (int) $settings['expanded_label_font_size'] . 'px;';
    $css .= '--fid-expanded-value-size:' . (int) $settings['expanded_value_font_size'] . 'px;';
    $css .= '--fid-logo-max-width:' . (int) $settings['logo_max_width'] . 'px;';
    $css .= '--fid-logo-max-height:' . (int) $settings['logo_max_height'] . 'px;';
    $css .= '--fid-first-col-width:' . (int) $settings['first_col_width'] . 'px;';
    $css .= '--fid-chevron-size:' . (int) $settings['chevron_size'] . 'px;';
    $css .= '}';

    return $css;
}

function airport_fid_init_updater() {
    $settings = airport_fid_get_settings();
    $repo = isset($settings['github_repo']) ? trim($settings['github_repo']) : '';
    $repo = airport_fid_normalize_github_repo($repo);
    if ($repo === '') {
        $repo = 'https://github.com/jkhliffz09/airport-fid/';
    }
    if ($repo === '') {
        return;
    }

    $loader = plugin_dir_path(__FILE__) . 'lib/plugin-update-checker/plugin-update-checker.php';
    if (!file_exists($loader)) {
        return;
    }

    require_once $loader;

    if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            $repo,
            __FILE__,
            'airport-fid-plugin'
        );

        if (!empty($settings['github_token'])) {
            $updateChecker->setAuthentication($settings['github_token']);
        }

        if (method_exists($updateChecker, 'getVcsApi')) {
            $api = $updateChecker->getVcsApi();
            if ($api && method_exists($api, 'enableReleaseAssets')) {
                $api->enableReleaseAssets();
            }
        }
    }
}
add_action('init', 'airport_fid_init_updater');

function airport_fid_normalize_github_repo($repo) {
    $repo = trim((string) $repo);
    if ($repo === '') {
        return '';
    }

    if (strpos($repo, 'git@') === 0) {
        // git@github.com:user/repo.git
        $repo = preg_replace('#^git@github\\.com:#', 'https://github.com/', $repo);
    }

    $repo = preg_replace('#\\.git$#', '', $repo);

    if (strpos($repo, 'http') !== 0) {
        $repo = 'https://github.com/' . ltrim($repo, '/');
    }

    return esc_url_raw($repo);
}

function airport_fid_shortcode($atts) {
    $settings = airport_fid_get_settings();

    $atts = shortcode_atts(
        array(
            'airport' => $settings['default_airport'],
            'use_geolocation' => (string) $settings['use_geolocation_default'],
            'show_destination' => '1',
            'limit' => $settings['max_flights'],
        ),
        $atts,
        'fid_board'
    );

    wp_enqueue_style('airport-fid-style');
    wp_enqueue_script('airport-fid-script');
    wp_add_inline_style('airport-fid-style', airport_fid_frontend_inline_css($settings));

    $config = array(
        'restUrl' => esc_url_raw(rest_url('airport-fid/v1')),
        'showDestination' => $atts['show_destination'] === '1',
        'defaultAirport' => strtoupper($atts['airport']),
        'useGeolocation' => $atts['use_geolocation'] === '1',
        'limit' => (int) $atts['limit'],
        'nonce' => wp_create_nonce('wp_rest'),
        'enableAnimation' => (int) $settings['enable_animation'] === 1,
        'defaultTheme' => $settings['default_theme'],
        'themeMode' => $settings['theme_mode'],
    );

    wp_localize_script('airport-fid-script', 'AirportFID', $config);

    $airport = esc_attr(strtoupper($atts['airport']));
    $use_geo = $atts['use_geolocation'] === '1' ? '1' : '0';
    $show_destination = $atts['show_destination'] === '1' ? '1' : '0';

    $uid = uniqid('airport-fid-');

    $output = '<div class="airport-fid-board" data-airport="' . $airport . '" data-use-geolocation="' . $use_geo . '" data-show-destination="' . $show_destination . '" data-limit="' . esc_attr((int) $atts['limit']) . '">';
    $output .= '<div class="airport-fid-header">';
    $output .= '<span>Flight Information</span>';
    $output .= '<button type="button" class="airport-fid-theme-toggle" aria-pressed="false">Light mode</button>';
    $output .= '</div>';
    $output .= '<div class="airport-fid-controls">';
    $output .= '<label class="airport-fid-label" for="' . esc_attr($uid) . '-input">Airport</label>';
    $output .= '<div class="airport-fid-input-row">';
    $output .= '<input type="text" id="' . esc_attr($uid) . '-input" class="airport-fid-input" placeholder="Airport name or IATA" />';
    $output .= '<button type="button" class="airport-fid-button airport-fid-button-ghost airport-fid-geo-inline"><span class="airport-fid-geo-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2.75a1 1 0 0 1 1 1v1.29a7 7 0 0 1 6.21 6.21h1.29a1 1 0 1 1 0 2h-1.29A7 7 0 0 1 13 19.25v1.29a1 1 0 1 1-2 0v-1.29A7 7 0 0 1 4.79 13H3.5a1 1 0 1 1 0-2h1.29A7 7 0 0 1 11 4.79V3.75a1 1 0 0 1 1-1zm0 4a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm0 2.75a2.25 2.25 0 1 1 0 4.5 2.25 2.25 0 0 1 0-4.5z" fill="currentColor"/></svg></span>Use my location</button>';
    $output .= '<button type="button" class="airport-fid-button airport-fid-load">Load</button>';
    $output .= '</div>';
    $output .= '<div class="airport-fid-suggest"></div>';
    $output .= '<label class="airport-fid-label" for="' . esc_attr($uid) . '-date">Date</label>';
    $output .= '<div class="airport-fid-input-row">';
    $output .= '<input type="date" id="' . esc_attr($uid) . '-date" class="airport-fid-input airport-fid-date" />';
    $output .= '<button type="button" class="airport-fid-button airport-fid-button-secondary airport-fid-date-button">Pick date</button>';
    $output .= '</div>';
    $output .= '<label class="airport-fid-label" for="' . esc_attr($uid) . '-sort">Sort By</label>';
    $output .= '<div class="airport-fid-input-row">';
    $output .= '<select id="' . esc_attr($uid) . '-sort" class="airport-fid-input airport-fid-sort">';
    $output .= '<option value="departure_time">Departure Time</option>';
    $output .= '<option value="arrival_time">Arrival Time</option>';
    $output .= '<option value="airport">Airport</option>';
    $output .= '<option value="airline">Airline</option>';
    $output .= '<option value="duration">Duration</option>';
    $output .= '</select>';
    $output .= '<select id="' . esc_attr($uid) . '-order" class="airport-fid-input airport-fid-order">';
    $output .= '<option value="asc">Ascending</option>';
    $output .= '<option value="desc">Descending</option>';
    $output .= '</select>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '<div class="airport-fid-status">Loading flight data...</div>';
    $output .= '<div class="airport-fid-table-wrapper"></div>';
    $output .= '<div class="airport-fid-pagination">';
    $output .= '<button type="button" class="airport-fid-button airport-fid-load-more">Load more</button>';
    $output .= '<div class="airport-fid-page-notice"></div>';
    $output .= '</div>';
    $output .= '<div class="airport-fid-loading" aria-hidden="true">';
    $output .= '<div class="airport-fid-loading-card">';
    $output .= '<div class="airport-fid-loading-ring"></div>';
    $output .= '<div class="airport-fid-loading-text">Searching flights...</div>';
    $output .= '</div>';
    $output .= '</div>';
    $output .= '</div>';

    return $output;
}
add_shortcode('fid_board', 'airport_fid_shortcode');

function airport_fid_register_routes() {
    register_rest_route('airport-fid/v1', '/nearest', array(
        'methods' => 'GET',
        'callback' => 'airport_fid_rest_nearest',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('airport-fid/v1', '/board', array(
        'methods' => 'GET',
        'callback' => 'airport_fid_rest_board',
        'permission_callback' => '__return_true',
        'args' => array(
            'airport' => array(
                'required' => true,
            ),
        ),
    ));

    register_rest_route('airport-fid/v1', '/routes', array(
        'methods' => 'GET',
        'callback' => 'airport_fid_rest_routes',
        'permission_callback' => '__return_true',
        'args' => array(
            'airport' => array(
                'required' => true,
            ),
        ),
    ));

    register_rest_route('airport-fid/v1', '/timetable', array(
        'methods' => 'GET',
        'callback' => 'airport_fid_rest_timetable',
        'permission_callback' => '__return_true',
        'args' => array(
            'airport' => array(
                'required' => true,
            ),
            'destination' => array(
                'required' => true,
            ),
        ),
    ));

    register_rest_route('airport-fid/v1', '/cache', array(
        'methods' => 'GET',
        'callback' => 'airport_fid_rest_cache_get',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('airport-fid/v1', '/cache', array(
        'methods' => 'POST',
        'callback' => 'airport_fid_rest_cache_set',
        'permission_callback' => 'airport_fid_can_write_cache',
    ));

    register_rest_route('airport-fid/v1', '/search-log', array(
        'methods' => 'POST',
        'callback' => 'airport_fid_rest_search_log_set',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('airport-fid/v1', '/remote-search-log', array(
        'methods' => 'POST',
        'callback' => 'airport_fid_rest_remote_search_log_set',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('airport-fid/v1', '/airports', array(
        'methods' => 'GET',
        'callback' => 'airport_fid_rest_airports',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'airport_fid_register_routes');

function airport_fid_rest_nearest(WP_REST_Request $request) {
    $lat = $request->get_param('lat');
    $lon = $request->get_param('lon');

    if ($lat === null || $lon === null) {
        return new WP_REST_Response(array('error' => 'Missing coordinates.'), 400);
    }

    $lat = (float) $lat;
    $lon = (float) $lon;

    $settings = airport_fid_get_settings();
    if (empty($settings['api_key'])) {
        return new WP_REST_Response(array('error' => 'API key not configured.'), 400);
    }

    $url = sprintf(
        'https://services.flightlookup.com/v1/xml/airports/nearest/%s/%s/?subscription-key=%s',
        rawurlencode($lat),
        rawurlencode($lon),
        rawurlencode($settings['api_key'])
    );

    $cache_key = 'airport_fid_nearest_' . md5($lat . '|' . $lon);
    $xml = airport_fid_get_xml($url, $cache_key);
    if (is_wp_error($xml)) {
        return new WP_REST_Response(array('error' => $xml->get_error_message()), 500);
    }

    $nearest = null;
    foreach ($xml->Airport as $airport) {
        $nearest = array(
            'code' => (string) $airport['IATACode'],
            'name' => (string) $airport['AirportName'],
            'distance' => (string) $airport['Distance'],
        );
        break;
    }

    if (!$nearest) {
        return new WP_REST_Response(array('error' => 'No airport found.'), 404);
    }

    return new WP_REST_Response($nearest, 200);
}

function airport_fid_rest_board(WP_REST_Request $request) {
    $airport = strtoupper(sanitize_text_field($request->get_param('airport')));
    $limit = (int) $request->get_param('limit');
    $debug = $request->get_param('debug') === '1';
    $debug_errors = array();
    $date_param = sanitize_text_field($request->get_param('date'));
    $sort = strtolower(sanitize_text_field($request->get_param('sort')));

    if (empty($airport)) {
        return new WP_REST_Response(array('error' => 'Airport is required.'), 400);
    }

    $settings = airport_fid_get_settings();
    if (empty($settings['api_key'])) {
        return new WP_REST_Response(array('error' => 'API key not configured.'), 400);
    }

    $max_destinations = (int) $settings['max_destinations'];
    if ($max_destinations === 0) {
        $max_destinations = PHP_INT_MAX;
    }
    if ($limit === 0) {
        $max_flights = PHP_INT_MAX;
    } elseif ($limit > 0) {
        $max_flights = min($limit, (int) $settings['max_flights']);
    } else {
        $max_flights = (int) $settings['max_flights'];
    }
    if ($max_flights === 0) {
        $max_flights = PHP_INT_MAX;
    }

    $routes_url = sprintf(
        'https://services.flightlookup.com/v1/xml/airports/%s/routes/nonstops?subscription-key=%s',
        rawurlencode($airport),
        rawurlencode($settings['api_key'])
    );

    $routes_xml = airport_fid_get_xml($routes_url, 'airport_fid_routes_' . md5($airport));
    if (is_wp_error($routes_xml)) {
        if ($debug) {
            $debug_errors[] = 'Routes error: ' . $routes_xml->get_error_message();
        }
        return new WP_REST_Response(array('error' => $routes_xml->get_error_message()), 500);
    }

    $airport_name = '';
    if (isset($routes_xml['FLSOriginName'])) {
        $airport_name = (string) $routes_xml['FLSOriginName'];
    } elseif (isset($routes_xml['OriginName'])) {
        $airport_name = (string) $routes_xml['OriginName'];
    }

    $destinations = airport_fid_extract_destinations($routes_xml, $airport, $max_destinations);
    if (empty($destinations)) {
        if ($debug) {
            $debug_errors[] = 'No destinations found for ' . $airport . '.';
        }
        return new WP_REST_Response(array('error' => 'No destinations found.'), 404);
    }

    if ($date_param && preg_match('/^\\d{8}$/', $date_param)) {
        $date = $date_param;
    } else {
        $date = wp_date('Ymd');
    }
    $flights = array();

    foreach ($destinations as $destination) {
        if (count($flights) >= $max_flights) {
            break;
        }
        $timetable_url = sprintf(
            'https://services.flightlookup.com/v1/xml/TimeTable/%s/%s/%s/?Airline=---&Language=en&Nofilter=Y&Compression=MOST&Connection=nonstop&Sort=1&subscription-key=%s',
            rawurlencode($airport),
            rawurlencode($destination),
            rawurlencode($date),
            rawurlencode($settings['api_key'])
        );

        $cache_key = 'airport_fid_timetable_' . md5($airport . '|' . $destination . '|' . $date);
        $timetable_xml = airport_fid_get_xml($timetable_url, $cache_key);
        if (is_wp_error($timetable_xml)) {
            if ($debug) {
                $debug_errors[] = 'Timetable error for ' . $airport . '->' . $destination . ': ' . $timetable_xml->get_error_message();
            }
            continue;
        }

        $parsed = airport_fid_parse_flights($timetable_xml, $max_flights - count($flights));
        if ($debug && empty($parsed)) {
            $debug_errors[] = 'No flights parsed for ' . $airport . '->' . $destination . ' on ' . $date . '.';
        }
        if (!empty($parsed)) {
            $flights = array_merge($flights, $parsed);
        }
    }

    if ($airport_name === '' && !empty($flights) && !empty($flights[0]['origin_name'])) {
        $airport_name = $flights[0]['origin_name'];
    }

    if ($sort !== 'arrival' && $sort !== 'duration') {
        $sort = 'departure';
    }

    usort($flights, function ($a, $b) use ($sort) {
        if ($sort === 'arrival') {
            $a_val = isset($a['arrival_ts']) ? (int) $a['arrival_ts'] : 0;
            $b_val = isset($b['arrival_ts']) ? (int) $b['arrival_ts'] : 0;
        } elseif ($sort === 'duration') {
            $a_val = isset($a['duration_minutes']) ? (int) $a['duration_minutes'] : 0;
            $b_val = isset($b['duration_minutes']) ? (int) $b['duration_minutes'] : 0;
        } else {
            $a_val = isset($a['departure_ts']) ? (int) $a['departure_ts'] : 0;
            $b_val = isset($b['departure_ts']) ? (int) $b['departure_ts'] : 0;
        }

        if ($a_val === $b_val) {
            return 0;
        }
        return $a_val < $b_val ? -1 : 1;
    });

    $payload = array(
        'airport' => $airport,
        'airport_name' => $airport_name,
        'date' => $date,
        'flights' => array_slice($flights, 0, $max_flights),
    );
    if ($debug) {
        $payload['errors'] = $debug_errors;
        $payload['destinations'] = $destinations;
    }

    return new WP_REST_Response($payload, 200);
}

function airport_fid_rest_routes(WP_REST_Request $request) {
    $airport = strtoupper(sanitize_text_field($request->get_param('airport')));
    $debug = $request->get_param('debug') === '1';
    $debug_errors = array();

    if (empty($airport)) {
        return new WP_REST_Response(array('error' => 'Airport is required.'), 400);
    }

    $settings = airport_fid_get_settings();
    if (empty($settings['api_key'])) {
        return new WP_REST_Response(array('error' => 'API key not configured.'), 400);
    }

    $max_destinations = (int) $settings['max_destinations'];
    if ($max_destinations === 0) {
        $max_destinations = PHP_INT_MAX;
    }

    $routes_url = sprintf(
        'https://services.flightlookup.com/v1/xml/airports/%s/routes/nonstops?subscription-key=%s',
        rawurlencode($airport),
        rawurlencode($settings['api_key'])
    );

    $routes_xml = airport_fid_get_xml($routes_url, 'airport_fid_routes_' . md5($airport));
    if (is_wp_error($routes_xml)) {
        if ($debug) {
            $debug_errors[] = 'Routes error: ' . $routes_xml->get_error_message();
        }
        return new WP_REST_Response(array('error' => $routes_xml->get_error_message()), 500);
    }

    $airport_name = '';
    if (isset($routes_xml['FLSOriginName'])) {
        $airport_name = (string) $routes_xml['FLSOriginName'];
    } elseif (isset($routes_xml['OriginName'])) {
        $airport_name = (string) $routes_xml['OriginName'];
    }

    $destinations = airport_fid_extract_destinations($routes_xml, $airport, $max_destinations);
    if (empty($destinations)) {
        if ($debug) {
            $debug_errors[] = 'No destinations found for ' . $airport . '.';
        }
        return new WP_REST_Response(array('error' => 'No destinations found.'), 404);
    }

    $payload = array(
        'airport' => $airport,
        'airport_name' => $airport_name,
        'destinations' => $destinations,
    );
    if ($debug) {
        $payload['errors'] = $debug_errors;
    }

    return new WP_REST_Response($payload, 200);
}

function airport_fid_rest_timetable(WP_REST_Request $request) {
    $airport = strtoupper(sanitize_text_field($request->get_param('airport')));
    $destination = strtoupper(sanitize_text_field($request->get_param('destination')));
    $date_param = sanitize_text_field($request->get_param('date'));
    $debug = $request->get_param('debug') === '1';
    $debug_errors = array();

    if (empty($airport) || empty($destination)) {
        return new WP_REST_Response(array('error' => 'Airport and destination are required.'), 400);
    }

    $settings = airport_fid_get_settings();
    if (empty($settings['api_key'])) {
        return new WP_REST_Response(array('error' => 'API key not configured.'), 400);
    }

    if ($date_param && preg_match('/^\\d{8}$/', $date_param)) {
        $date = $date_param;
    } else {
        $date = wp_date('Ymd');
    }

    $max_flights = (int) $settings['max_flights'];
    if ($max_flights === 0) {
        $max_flights = PHP_INT_MAX;
    }

    $timetable_url = sprintf(
        'https://services.flightlookup.com/v1/xml/TimeTable/%s/%s/%s/?Airline=---&Language=en&Nofilter=Y&Compression=MOST&Connection=nonstop&Sort=1&subscription-key=%s',
        rawurlencode($airport),
        rawurlencode($destination),
        rawurlencode($date),
        rawurlencode($settings['api_key'])
    );

    $cache_key = 'airport_fid_timetable_' . md5($airport . '|' . $destination . '|' . $date);
    $timetable_xml = airport_fid_get_xml($timetable_url, $cache_key);
    if (is_wp_error($timetable_xml)) {
        if ($debug) {
            $debug_errors[] = 'Timetable error for ' . $airport . '->' . $destination . ': ' . $timetable_xml->get_error_message();
        }
        return new WP_REST_Response(array('error' => $timetable_xml->get_error_message()), 500);
    }

    $flights = airport_fid_parse_flights($timetable_xml, $max_flights);

    $payload = array(
        'airport' => $airport,
        'destination' => $destination,
        'date' => $date,
        'flights' => $flights,
    );
    if ($debug) {
        $payload['errors'] = $debug_errors;
    }

    return new WP_REST_Response($payload, 200);
}

function airport_fid_can_write_cache(WP_REST_Request $request) {
    $nonce = $request->get_header('X-WP-Nonce');
    return $nonce && wp_verify_nonce($nonce, 'wp_rest');
}

function airport_fid_rest_cache_get(WP_REST_Request $request) {
    $airport = strtoupper(sanitize_text_field($request->get_param('airport')));
    $date_param = sanitize_text_field($request->get_param('date'));
    $sort = sanitize_text_field($request->get_param('sort'));

    if (empty($airport)) {
        return new WP_REST_Response(array('error' => 'Airport is required.'), 400);
    }

    if ($date_param && preg_match('/^\\d{8}$/', $date_param)) {
        $date = $date_param;
    } else {
        $date = wp_date('Ymd');
    }

    if (empty($sort)) {
        $sort = 'departure_time';
    }

    $cached = airport_fid_get_cache($airport, $date, $sort);
    if (!$cached) {
        return new WP_REST_Response(array(
            'cached' => false,
            'stale' => true,
            'flights' => array(),
        ), 200);
    }

    $payload = is_array($cached['payload']) ? $cached['payload'] : array();
    $flights = isset($payload['flights']) && is_array($payload['flights']) ? $payload['flights'] : array();
    $missing_day_indicator = false;
    foreach ($flights as $flight) {
        if (!is_array($flight) || !array_key_exists('day_indicator', $flight)) {
            $missing_day_indicator = true;
            break;
        }
    }

    return new WP_REST_Response(array(
        'cached' => true,
        'stale' => (bool) $cached['stale'] || $missing_day_indicator,
        'airport_name' => isset($payload['airport_name']) ? $payload['airport_name'] : '',
        'flights' => $flights,
        'updated_at' => $cached['updated_at'],
    ), 200);
}

function airport_fid_rest_cache_set(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $airport = strtoupper(sanitize_text_field($params['airport'] ?? ''));
    $date = sanitize_text_field($params['date'] ?? '');
    $sort = sanitize_text_field($params['sort'] ?? '');
    $airport_name = sanitize_text_field($params['airport_name'] ?? '');
    $flights = $params['flights'] ?? array();

    if (empty($airport) || empty($date) || empty($sort)) {
        return new WP_REST_Response(array('error' => 'Airport, date, and sort are required.'), 400);
    }
    if (!preg_match('/^\\d{8}$/', $date)) {
        return new WP_REST_Response(array('error' => 'Invalid date format.'), 400);
    }
    if (!is_array($flights)) {
        return new WP_REST_Response(array('error' => 'Flights payload must be an array.'), 400);
    }

    $payload = array(
        'airport' => $airport,
        'airport_name' => $airport_name,
        'date' => $date,
        'flights' => $flights,
    );

    $cache_id = airport_fid_set_cache($airport, $date, $sort, $payload);

    return new WP_REST_Response(array('saved' => true, 'cache_id' => $cache_id), 200);
}

function airport_fid_rest_search_log_set(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $airport = strtoupper(sanitize_text_field((string) ($params['airport'] ?? '')));
    $date = sanitize_text_field((string) ($params['date'] ?? ''));
    $sort = sanitize_text_field((string) ($params['sort'] ?? 'departure_time'));
    $source = sanitize_text_field((string) ($params['source'] ?? 'manual'));
    $raw_input = sanitize_text_field((string) ($params['raw_input'] ?? ''));
    $selected_airport = sanitize_text_field((string) ($params['selected_airport'] ?? ''));
    $cache_id = isset($params['cache_id']) ? (int) $params['cache_id'] : 0;

    if ($airport === '' || !preg_match('/^\d{8}$/', $date)) {
        return new WP_REST_Response(array('error' => 'Airport and valid date are required.'), 400);
    }

    $log_id = airport_fid_log_search(array(
        'airport' => $airport,
        'date' => $date,
        'sort' => $sort,
        'source' => $source,
        'raw_input' => $raw_input,
        'selected_airport' => $selected_airport,
        'cache_id' => $cache_id,
    ));

    return new WP_REST_Response(array('saved' => true, 'log_id' => $log_id), 200);
}

function airport_fid_rest_remote_search_log_set(WP_REST_Request $request) {
    $settings = airport_fid_get_settings();
    $header_key = sanitize_text_field((string) $request->get_header('X-Airport-FID-Key'));
    $hub_enabled = (int) ($settings['analytics_hub_enabled'] ?? 0) === 1;
    $hub_key = trim((string) ($settings['analytics_hub_key'] ?? ''));

    if (!$hub_enabled || $hub_key === '' || !hash_equals($hub_key, $header_key)) {
        return new WP_REST_Response(array('error' => 'Unauthorized remote analytics request.'), 403);
    }

    $params = $request->get_json_params();
    $airport = strtoupper(sanitize_text_field((string) ($params['airport'] ?? '')));
    $date = sanitize_text_field((string) ($params['date'] ?? ''));
    $sort = sanitize_text_field((string) ($params['sort'] ?? 'departure_time'));
    $source = sanitize_text_field((string) ($params['source'] ?? 'manual'));
    $raw_input = sanitize_text_field((string) ($params['raw_input'] ?? ''));
    $selected_airport = sanitize_text_field((string) ($params['selected_airport'] ?? ''));
    $site_url = esc_url_raw((string) ($params['site_url'] ?? ''));
    $site_label = sanitize_text_field((string) ($params['site_label'] ?? ''));
    $created_at = sanitize_text_field((string) ($params['created_at'] ?? current_time('mysql')));

    if ($airport === '' || !preg_match('/^\d{8}$/', $date)) {
        return new WP_REST_Response(array('error' => 'Airport and valid date are required.'), 400);
    }

    $log_id = airport_fid_log_search(array(
        'airport' => $airport,
        'date' => $date,
        'sort' => $sort,
        'source' => $source,
        'raw_input' => $raw_input,
        'selected_airport' => $selected_airport,
        'site_url' => $site_url,
        'site_label' => $site_label,
        'created_at' => $created_at,
        'skip_remote' => true,
    ));

    return new WP_REST_Response(array('saved' => true, 'log_id' => $log_id), 200);
}

function airport_fid_rest_airports(WP_REST_Request $request) {
    $query = strtoupper(sanitize_text_field($request->get_param('query')));
    if (strlen($query) < 3) {
        return new WP_REST_Response(array(), 200);
    }

    $airports = airport_fid_get_airport_index();
    if (is_wp_error($airports)) {
        return new WP_REST_Response(array('error' => $airports->get_error_message()), 500);
    }

    $matches = array();
    foreach ($airports as $airport) {
        if (strpos($airport['code'], $query) === 0 || stripos($airport['name'], $query) !== false) {
            $matches[] = $airport;
        }
        if (count($matches) >= 10) {
            break;
        }
    }

    return new WP_REST_Response($matches, 200);
}

function airport_fid_get_airport_index() {
    $cache_key = 'airport_fid_airports_index';
    $cached = get_transient($cache_key);
    if ($cached) {
        return $cached;
    }

    $path = plugin_dir_path(__FILE__) . 'assets/airports.xml';
    if (!file_exists($path)) {
        return new WP_Error('airport_fid_airports_missing', 'Airports data not found.');
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return new WP_Error('airport_fid_airports_missing', 'Airports data not found.');
    }

    $start = strpos($contents, '<Airports');
    if ($start !== false) {
        $contents = substr($contents, $start);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($contents);
    if (!$xml) {
        return new WP_Error('airport_fid_airports_invalid', 'Invalid airports XML data.');
    }

    $airports = array();
    foreach ($xml->Airport as $airport) {
        $code = strtoupper((string) $airport['IATACode']);
        $name = (string) $airport['Name'];
        if ($code === '') {
            continue;
        }
        $airports[] = array(
            'code' => $code,
            'name' => $name,
        );
    }

    set_transient($cache_key, $airports, 7 * DAY_IN_SECONDS);

    return $airports;
}

function airport_fid_get_xml($url, $cache_key) {
    $settings = airport_fid_get_settings();
    $ttl = max(1, (int) $settings['cache_ttl_minutes']) * MINUTE_IN_SECONDS;

    $cached = get_transient($cache_key);
    if ($cached) {
        return airport_fid_parse_xml_string($cached);
    }

    $response = wp_remote_get($url, array(
        'timeout' => 20,
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('airport_fid_http_error', 'FlightLookup API error: ' . $code);
    }

    $body = wp_remote_retrieve_body($response);
    if (!$body) {
        return new WP_Error('airport_fid_empty_response', 'Empty response from FlightLookup API.');
    }

    $parsed = airport_fid_parse_xml_string($body);
    if (is_wp_error($parsed)) {
        return $parsed;
    }

    set_transient($cache_key, $body, $ttl);

    return $parsed;
}

function airport_fid_parse_xml_string($body) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if (!$xml) {
        return new WP_Error('airport_fid_invalid_xml', 'Invalid XML from FlightLookup API.');
    }

    return $xml;
}

function airport_fid_extract_destinations($xml, $origin, $limit) {
    $destinations = array();
    if (!isset($xml->Routes->NonStops)) {
        return $destinations;
    }

    foreach ($xml->Routes->NonStops->NonStop as $route) {
        $from = strtoupper((string) $route['From']);
        $to = strtoupper((string) $route['To']);
        if ($from !== $origin || empty($to)) {
            continue;
        }
        if (!in_array($to, $destinations, true)) {
            $destinations[] = $to;
        }
        if (count($destinations) >= $limit) {
            break;
        }
    }

    return $destinations;
}

function airport_fid_parse_flights($xml, $limit) {
    $flights = array();
    $flight_nodes = $xml->xpath('//*[local-name()="FlightDetails"]');
    if (!$flight_nodes) {
        return $flights;
    }

    $equipment_map = airport_fid_get_equipment_index();
    if (is_wp_error($equipment_map)) {
        $equipment_map = array();
    }

    foreach ($flight_nodes as $flight) {
        if (count($flights) >= $limit) {
            break;
        }

        $attributes = $flight->attributes();
        $departure = (string) $attributes['FLSDepartureDateTime'];
        $departure_offset = (string) $attributes['FLSDepartureTimeOffset'];
        $arrival = (string) $attributes['FLSArrivalDateTime'];
        $arrival_offset = (string) $attributes['FLSArrivalTimeOffset'];
        $total_flight_time = (string) $attributes['TotalFlightTime'];
        $day_indicator = '';
        $destination = (string) $attributes['FLSArrivalCode'];
        $destination_name = (string) $attributes['FLSArrivalName'];
        $origin_name = (string) $attributes['FLSDepartureName'];
        $origin_code = (string) $attributes['FLSDepartureCode'];

        $legs = $flight->xpath('.//*[local-name()="FlightLegDetails"]');
        if (!$legs || !isset($legs[0])) {
            continue;
        }

        $first_leg = $legs[0];
        $last_leg = $legs[count($legs) - 1];
        $journey_duration = (string) $first_leg['JourneyDuration'];

        $marketing_list = $first_leg->xpath('.//*[local-name()="MarketingAirline"]');
        $operating_list = $first_leg->xpath('.//*[local-name()="OperatingAirline"]');
        $marketing = $marketing_list ? $marketing_list[0] : null;
        $operating = $operating_list ? $operating_list[0] : null;

        $airline_code = $marketing && isset($marketing['Code']) ? (string) $marketing['Code'] : '';
        $airline_name = $marketing && isset($marketing['CompanyShortName']) ? (string) $marketing['CompanyShortName'] : '';
        if (!$airline_name && $operating && isset($operating['CompanyShortName'])) {
            $airline_name = (string) $operating['CompanyShortName'];
        }
        if (!$airline_name) {
            $airline_name = $airline_code;
        }
        if (!$airline_code && $operating && isset($operating['Code'])) {
            $airline_code = (string) $operating['Code'];
        }

        $flight_number = (string) $last_leg['FlightNumber'];
        if (!$flight_number) {
            $flight_number = (string) $first_leg['FlightNumber'];
        }
        if (!$flight_number && $operating && isset($operating['FlightNumber'])) {
            $flight_number = (string) $operating['FlightNumber'];
        }
        if ($airline_code) {
            $flight_number = $airline_code . $flight_number;
        }

        $departure_terminal = '';
        $arrival_terminal = '';
        $equipment = '';
        $equipment_name = '';

        $departure_airport = $first_leg->xpath('.//*[local-name()="DepartureAirport"]');
        if ($departure_airport && isset($departure_airport[0]['Terminal'])) {
            $departure_terminal = (string) $departure_airport[0]['Terminal'];
        }
        if (!$origin_name && $departure_airport && isset($departure_airport[0]['FLSLocationName'])) {
            $origin_name = (string) $departure_airport[0]['FLSLocationName'];
        }
        if (!$origin_code && $departure_airport && isset($departure_airport[0]['LocationCode'])) {
            $origin_code = (string) $departure_airport[0]['LocationCode'];
        }

        $arrival_airport = $last_leg->xpath('.//*[local-name()="ArrivalAirport"]');
        if ($arrival_airport && isset($arrival_airport[0]['Terminal'])) {
            $arrival_terminal = (string) $arrival_airport[0]['Terminal'];
        }
        if ($arrival_airport && isset($arrival_airport[0]['FLSDayIndicator'])) {
            $day_indicator = (string) $arrival_airport[0]['FLSDayIndicator'];
        }

        if (!$destination && $arrival_airport && isset($arrival_airport[0]['LocationCode'])) {
            $destination = (string) $arrival_airport[0]['LocationCode'];
        }
        if (!$destination_name && $arrival_airport && isset($arrival_airport[0]['FLSLocationName'])) {
            $destination_name = (string) $arrival_airport[0]['FLSLocationName'];
        }

        $equipment_nodes = $first_leg->xpath('.//*[local-name()="Equipment"]');
        if ($equipment_nodes && isset($equipment_nodes[0]['AirEquipType'])) {
            $equipment = (string) $equipment_nodes[0]['AirEquipType'];
        }
        if ($equipment && isset($equipment_map[$equipment])) {
            $equipment_name = $equipment_map[$equipment];
        }

        $terminal = airport_fid_format_terminal($departure_terminal, $arrival_terminal);

        $departure_ts = $departure ? strtotime($departure) : 0;
        $arrival_ts = $arrival ? strtotime($arrival) : 0;
        $duration_source = $total_flight_time ?: $journey_duration;
        $duration_minutes = $duration_source ? airport_fid_duration_to_minutes($duration_source) : 0;

        $flights[] = array(
            'airline' => $airline_name,
            'airline_code' => $airline_code,
            'flight_number' => $flight_number,
            'departure_time' => airport_fid_format_time($departure),
            'arrival_time' => airport_fid_format_time($arrival),
            'departure_date' => airport_fid_format_date($departure),
            'arrival_date' => airport_fid_format_date($arrival),
            'day_indicator' => $day_indicator,
            'departure_ts' => $departure_ts,
            'arrival_ts' => $arrival_ts,
            'duration_minutes' => $duration_minutes,
            'duration_label' => airport_fid_format_duration($duration_minutes, $duration_source),
            'status' => airport_fid_calculate_status($departure, $departure_offset, $arrival, $arrival_offset),
            'terminal' => $terminal,
            'destination' => $destination,
            'destination_name' => $destination_name,
            'origin_name' => $origin_name,
            'origin_code' => $origin_code,
            'equipment' => $equipment,
            'equipment_name' => $equipment_name,
        );
    }

    return $flights;
}

function airport_fid_get_equipment_index() {
    $cache_key = 'airport_fid_equipment_index';
    $cached = get_transient($cache_key);
    if ($cached) {
        return $cached;
    }

    $path = plugin_dir_path(__FILE__) . 'assets/equipment.xml';
    if (!file_exists($path)) {
        return new WP_Error('airport_fid_equipment_missing', 'Equipment data not found.');
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return new WP_Error('airport_fid_equipment_missing', 'Equipment data not found.');
    }

    $start = strpos($contents, '<Equipments');
    if ($start !== false) {
        $contents = substr($contents, $start);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($contents);
    if (!$xml) {
        return new WP_Error('airport_fid_equipment_invalid', 'Invalid equipment XML data.');
    }

    $equipment = array();
    foreach ($xml->Equipment as $item) {
        $code = strtoupper((string) $item['IATACode']);
        $name = (string) $item['Name'];
        if ($code === '') {
            continue;
        }
        $equipment[$code] = $name;
    }

    set_transient($cache_key, $equipment, 7 * DAY_IN_SECONDS);

    return $equipment;
}

function airport_fid_format_time($date_time) {
    if (empty($date_time) || strlen($date_time) < 16) {
        return '--';
    }

    return substr($date_time, 11, 5);
}

function airport_fid_format_date($date_time) {
    if (empty($date_time) || strlen($date_time) < 10) {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($date_time);
        return $dt->format('D j M Y');
    } catch (Exception $e) {
        return substr($date_time, 0, 10);
    }
}

function airport_fid_duration_to_minutes($duration) {
    if (empty($duration)) {
        return 0;
    }

    if (preg_match('/PT(?:(\\d+)H)?(?:(\\d+)M)?/i', $duration, $matches)) {
        $hours = isset($matches[1]) ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) ? (int) $matches[2] : 0;
        return ($hours * 60) + $minutes;
    }

    return 0;
}

function airport_fid_format_duration($minutes, $fallback_duration = '') {
    $total = (int) $minutes;
    if ($total <= 0 && $fallback_duration) {
        $total = airport_fid_duration_to_minutes($fallback_duration);
    }
    if ($total <= 0) {
        return '--';
    }

    $hours = (int) floor($total / 60);
    $mins = $total % 60;
    $parts = array();
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($mins > 0 || empty($parts)) {
        $parts[] = $mins . 'm';
    }
    return implode(' ', $parts);
}

function airport_fid_format_terminal($departure_terminal, $arrival_terminal) {
    $departure_terminal = trim((string) $departure_terminal);
    $arrival_terminal = trim((string) $arrival_terminal);

    if ($departure_terminal === '' && $arrival_terminal === '') {
        return '--';
    }

    if ($departure_terminal === '') {
        return $arrival_terminal;
    }

    if ($arrival_terminal === '') {
        return $departure_terminal;
    }

    return 'T' . $departure_terminal . ' -> T' . $arrival_terminal;
}

function airport_fid_calculate_status($departure, $departure_offset, $arrival, $arrival_offset) {
    $departure_dt = airport_fid_build_datetime($departure, $departure_offset);
    $arrival_dt = airport_fid_build_datetime($arrival, $arrival_offset);

    if (!$departure_dt || !$arrival_dt) {
        return 'Scheduled';
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $departure_utc = $departure_dt->setTimezone(new DateTimeZone('UTC'));
    $arrival_utc = $arrival_dt->setTimezone(new DateTimeZone('UTC'));

    $boarding_start = $departure_utc->modify('-60 minutes');
    $gate_close = $departure_utc->modify('+15 minutes');
    $arrival_window = $arrival_utc->modify('-30 minutes');
    $arrival_complete = $arrival_utc->modify('+60 minutes');

    if ($now < $boarding_start) {
        return 'Scheduled';
    }

    if ($now >= $boarding_start && $now < $gate_close) {
        return 'Boarding';
    }

    if ($now >= $gate_close && $now < $arrival_window) {
        return 'In Air';
    }

    if ($now >= $arrival_window && $now < $arrival_complete) {
        return 'Arriving';
    }

    return 'Arrived';
}

function airport_fid_build_datetime($date_time, $offset) {
    if (empty($date_time)) {
        return null;
    }

    $offset = trim((string) $offset);
    if ($offset !== '' && strpos($offset, ':') === false && strlen($offset) === 5) {
        $offset = substr($offset, 0, 3) . ':' . substr($offset, 3, 2);
    }

    $full = $date_time;
    if ($offset !== '') {
        $full .= $offset;
    }

    try {
        return new DateTimeImmutable($full);
    } catch (Exception $e) {
        return null;
    }
}
