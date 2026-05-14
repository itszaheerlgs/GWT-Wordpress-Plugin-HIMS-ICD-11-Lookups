<?php
/**
 * Plugin Name:       HIMS ICD-11 Lookup
 * Plugin URI:        https://github.com/itszaheerlgs
 * Description:       Full-featured ICD-11 MMS (2025-01) search, browse, code info, postcoordination, history & export — powered by the WHO ICD-API v2.
 * Version:           2.0.0
 * Author:            Dether / Zaheer S. Lagos — I.T. NGANI, HIMS-DGTHMC
 * Author URI:        https://gravatar.com/detherslagos
 * License:           GPL-2.0+
 * Text Domain:       hims-icd11
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'HIMS_ICD11_VERSION',    '2.0.0' );
define( 'HIMS_ICD11_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIMS_ICD11_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HIMS_ICD11_OPTION',     'hims_icd11_settings' );

/* ── Autoload includes ── */
require_once HIMS_ICD11_PLUGIN_DIR . 'includes/class-icd11-api.php';
require_once HIMS_ICD11_PLUGIN_DIR . 'includes/class-icd11-cache.php';
require_once HIMS_ICD11_PLUGIN_DIR . 'includes/class-icd11-history.php';
require_once HIMS_ICD11_PLUGIN_DIR . 'admin/class-icd11-admin.php';
require_once HIMS_ICD11_PLUGIN_DIR . 'public/class-icd11-public.php';

/* ── Bootstrap ── */
add_action( 'plugins_loaded', [ 'HIMS_ICD11_Admin',  'init' ] );
add_action( 'plugins_loaded', [ 'HIMS_ICD11_Public', 'init' ] );

/* ── Activation / Deactivation ── */
register_activation_hook(   __FILE__, 'hims_icd11_activate'   );
register_deactivation_hook( __FILE__, 'hims_icd11_deactivate' );

function hims_icd11_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'icd11_history';

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        code        VARCHAR(50)  NOT NULL,
        title       VARCHAR(500) NOT NULL,
        searched_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY searched_at (searched_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    add_option( HIMS_ICD11_OPTION, [
        'client_id'     => '',
        'client_secret' => '',
        'release'       => '2025-01',
        'language'      => 'en',
        'cache_ttl'     => 3600,
        'per_page'      => 10,
    ]);
}

function hims_icd11_deactivate() {
    delete_transient( 'hims_icd11_token' );
}
