<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HIMS_ICD11_History {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'icd11_history';
    }

    /* Add a search record */
    public static function add( $code, $title, $user_id = 0 ) {
        global $wpdb;
        if ( ! $user_id ) $user_id = get_current_user_id();

        // Avoid duplicates — update timestamp if exists
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE user_id=%d AND code=%s",
            $user_id, $code
        ));

        if ( $existing ) {
            $wpdb->update( self::table(),
                [ 'searched_at' => current_time('mysql'), 'title' => $title ],
                [ 'id' => $existing ],
                [ '%s', '%s' ], [ '%d' ]
            );
        } else {
            $wpdb->insert( self::table(), [
                'user_id'    => $user_id,
                'code'       => sanitize_text_field( $code ),
                'title'      => sanitize_text_field( $title ),
                'searched_at'=> current_time('mysql'),
            ], [ '%d', '%s', '%s', '%s' ]);
        }
    }

    /* Get recent history for a user */
    public static function get( $user_id = 0, $limit = 20 ) {
        global $wpdb;
        if ( ! $user_id ) $user_id = get_current_user_id();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE user_id=%d ORDER BY searched_at DESC LIMIT %d",
            $user_id, $limit
        ));
    }

    /* Delete one record */
    public static function delete( $id, $user_id = 0 ) {
        global $wpdb;
        if ( ! $user_id ) $user_id = get_current_user_id();
        $wpdb->delete( self::table(),
            [ 'id' => $id, 'user_id' => $user_id ],
            [ '%d', '%d' ]
        );
    }

    /* Clear all for user */
    public static function clear( $user_id = 0 ) {
        global $wpdb;
        if ( ! $user_id ) $user_id = get_current_user_id();
        $wpdb->delete( self::table(), [ 'user_id' => $user_id ], [ '%d' ] );
    }

    /* Admin: get all */
    public static function get_all( $limit = 100, $offset = 0 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT h.*, u.display_name FROM " . self::table() . " h LEFT JOIN {$wpdb->users} u ON h.user_id=u.ID ORDER BY h.searched_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    /* Count all records */
    public static function count_all() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() );
    }
}
