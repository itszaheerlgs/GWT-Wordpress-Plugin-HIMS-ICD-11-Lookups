<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HIMS_ICD11_Cache {

    public static function get( $key ) {
        return get_transient( $key );
    }

    public static function set( $key, $value, $ttl = 3600 ) {
        set_transient( $key, $value, $ttl );
    }

    public static function delete( $key ) {
        delete_transient( $key );
    }

    public static function flush_all() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hims_icd11_%' OR option_name LIKE '_transient_timeout_hims_icd11_%'"
        );
        delete_transient( 'hims_icd11_token' );
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_hims_icd11_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );
    }
}
