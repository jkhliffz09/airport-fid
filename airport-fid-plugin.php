<?php
/**
 * Plugin Name: Airport FID Board
 * Description: Display flight information in a FID-style table using FlightLookup XML APIs.
 * Version: 0.1.46
 * Author: khliffz
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

const AIRPORT_FID_OPTION_KEY = 'airport_fid_settings';
const AIRPORT_FID_VERSION = '0.1.46';

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
        '<input type="number" name="%s[max_destinations]" value="%d" class="small-text" min="1" max="50" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        (int) $settings['max_destinations']
    );
}

function airport_fid_max_flights_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="number" name="%s[max_flights]" value="%d" class="small-text" min="1" max="200" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        (int) $settings['max_flights']
    );
}

function airport_fid_cache_ttl_field() {
    $settings = airport_fid_get_settings();
    printf(
        '<input type="number" name="%s[cache_ttl_minutes]" value="%d" class="small-text" min="1" max="1440" />',
        esc_attr(AIRPORT_FID_OPTION_KEY),
        (int) $settings['cache_ttl_minutes']
    );
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
    $clean['max_destinations'] = isset($settings['max_destinations']) ? max(1, (int) $settings['max_destinations']) : $defaults['max_destinations'];
    $clean['max_flights'] = isset($settings['max_flights']) ? max(1, (int) $settings['max_flights']) : $defaults['max_flights'];
    $clean['cache_ttl_minutes'] = isset($settings['cache_ttl_minutes']) ? max(1, (int) $settings['cache_ttl_minutes']) : $defaults['cache_ttl_minutes'];

    return array_merge($defaults, $clean);
}

function airport_fid_register_menu() {
    add_options_page(
        'Airport FID Board',
        'Airport FID Board',
        'manage_options',
        'airport-fid-settings',
        'airport_fid_render_settings_page'
    );
}
add_action('admin_menu', 'airport_fid_register_menu');

function airport_fid_render_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>Airport FID Board</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('airport_fid_settings_group');
    do_settings_sections('airport-fid-settings');
    submit_button();
    echo '</form>';
    echo '</div>';
}

function airport_fid_register_assets() {
    wp_register_style(
        'airport-fid-style',
        plugins_url('assets/css/fid.css', __FILE__),
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

    $config = array(
        'restUrl' => esc_url_raw(rest_url('airport-fid/v1')),
        'showDestination' => $atts['show_destination'] === '1',
        'defaultAirport' => strtoupper($atts['airport']),
        'useGeolocation' => $atts['use_geolocation'] === '1',
        'limit' => (int) $atts['limit'],
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

    if (empty($airport)) {
        return new WP_REST_Response(array('error' => 'Airport is required.'), 400);
    }

    $settings = airport_fid_get_settings();
    if (empty($settings['api_key'])) {
        return new WP_REST_Response(array('error' => 'API key not configured.'), 400);
    }

    $max_destinations = (int) $settings['max_destinations'];
    if ($limit === 0) {
        $max_flights = PHP_INT_MAX;
    } elseif ($limit > 0) {
        $max_flights = min($limit, (int) $settings['max_flights']);
    } else {
        $max_flights = (int) $settings['max_flights'];
    }

    $routes_url = sprintf(
        'https://services.flightlookup.com/v1/xml/airports/%s/routes?subscription-key=%s',
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
            'https://services.flightlookup.com/v1/xml/TimeTable/%s/%s/%s/?Airline=---&Language=en&Nofilter=Y&Compression=MOST&subscription-key=%s',
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

        $flight_number = (string) $first_leg['FlightNumber'];
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

        $flights[] = array(
            'airline' => $airline_name,
            'airline_code' => $airline_code,
            'flight_number' => $flight_number,
            'departure_time' => airport_fid_format_time($departure),
            'arrival_time' => airport_fid_format_time($arrival),
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
