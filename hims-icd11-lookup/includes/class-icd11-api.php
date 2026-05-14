<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HIMS_ICD11_API {

    const TOKEN_URL  = 'https://icdaccessmanagement.who.int/connect/token';
    const API_BASE   = 'https://id.who.int/icd';
    const API_VER    = 'v2';

    private static $opts = null;

    /* ── Settings ── */
    private static function opts() {
        if ( null === self::$opts ) {
            self::$opts = get_option( HIMS_ICD11_OPTION, [] );
        }
        return self::$opts;
    }

    /* ════════════════════════════════════════
       AUTH — OAuth2 Client Credentials
    ════════════════════════════════════════ */
    public static function get_token() {
        $cached = get_transient( 'hims_icd11_token' );
        if ( $cached ) return $cached;

        $opts = self::opts();
        if ( empty( $opts['client_id'] ) || empty( $opts['client_secret'] ) ) {
            return new WP_Error( 'no_credentials', __( 'ICD-11 API credentials not configured.', 'hims-icd11' ) );
        }

        $response = wp_remote_post( self::TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'client_id'     => $opts['client_id'],
                'client_secret' => $opts['client_secret'],
                'scope'         => 'icdapi_access',
                'grant_type'    => 'client_credentials',
            ],
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            return new WP_Error( 'token_error', __( 'Failed to obtain ICD-11 access token.', 'hims-icd11' ) );
        }

        $ttl = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 60 : 3540;
        set_transient( 'hims_icd11_token', $body['access_token'], $ttl );
        return $body['access_token'];
    }

    /* ════════════════════════════════════════
       REQUEST HELPER
    ════════════════════════════════════════ */
    private static function request( $url, $lang = null ) {
        $opts  = self::opts();
        $token = self::get_token();
        if ( is_wp_error( $token ) ) return $token;

        $lang = $lang ?: ( $opts['language'] ?? 'en' );

        $cache_key = 'hims_icd11_' . md5( $url . $lang );
        $cached    = HIMS_ICD11_Cache::get( $cache_key );
        if ( false !== $cached ) return $cached;

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization'  => 'Bearer ' . $token,
                'Accept'         => 'application/json',
                'Accept-Language'=> $lang,
                'API-Version'    => self::API_VER,
            ],
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 ) {
            delete_transient( 'hims_icd11_token' );
            return new WP_Error( 'unauthorized', __( 'ICD-11 token expired. Please retry.', 'hims-icd11' ) );
        }
        if ( $code !== 200 ) {
            return new WP_Error( 'api_error', sprintf( __( 'ICD-11 API error %d', 'hims-icd11' ), $code ) );
        }

        $ttl = intval( $opts['cache_ttl'] ?? 3600 );
        HIMS_ICD11_Cache::set( $cache_key, $body, $ttl );
        return $body;
    }

    /* ════════════════════════════════════════
       SEARCH  — /release/11/{release}/mms/search
    ════════════════════════════════════════ */
    public static function search( $query, $args = [] ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';

        $params = array_merge([
            'q'                         => $query,
            'useFlexisearch'            => 'false',
            'flatResults'               => 'true',
            'highlightingEnabled'       => 'true',
            'medicalCodingMode'         => 'true',
            'includeKeywordResult'      => 'true',
            'chapterFilter'             => '',
            'subtreesFilter'            => '',
        ], $args );

        // Remove empty params
        $params = array_filter( $params, fn($v) => $v !== '' );

        $url = self::API_BASE . "/release/11/{$release}/mms/search?" . http_build_query( $params );
        return self::request( $url, $args['lang'] ?? null );
    }

    /* ════════════════════════════════════════
       ENTITY DETAIL — /release/11/{release}/mms/{id}
    ════════════════════════════════════════ */
    public static function get_entity( $entity_id, $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms/{$entity_id}";
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       CODE INFO — decode code/postcoordination string
    ════════════════════════════════════════ */
    public static function get_code_info( $code_string, $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms/codeinfo/" . rawurlencode( $code_string );
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       LINEARIZATION ROOT (chapters)
    ════════════════════════════════════════ */
    public static function get_root( $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms";
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       CHILDREN of an entity
    ════════════════════════════════════════ */
    public static function get_children( $entity_id, $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms/{$entity_id}/children";
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       ANCESTORS of an entity
    ════════════════════════════════════════ */
    public static function get_ancestors( $entity_id, $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms/{$entity_id}/ancestors";
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       FOUNDATION ENTITY (full detail)
    ════════════════════════════════════════ */
    public static function get_foundation_entity( $entity_id, $lang = null ) {
        $url = self::API_BASE . "/entity/{$entity_id}";
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       AVAILABLE RELEASES
    ════════════════════════════════════════ */
    public static function get_releases( $lang = null ) {
        $url = self::API_BASE . '/release/11';
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       ICD-10 LOOKUP (for crosswalk)
    ════════════════════════════════════════ */
    public static function get_icd10( $code, $lang = null ) {
        $url = self::API_BASE . '/release/10/2016/' . rawurlencode( $code );
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       WORD SUGGESTIONS (autocomplete)
    ════════════════════════════════════════ */
    public static function suggest( $query, $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms/autocode?targetEntityType=Category&matchThreshold=0.5&"
                 . http_build_query([ 'searchText' => $query ]);
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       POSTCOORDINATION SCALE for an entity
    ════════════════════════════════════════ */
    public static function get_postcoordination_scale( $entity_id, $lang = null ) {
        $opts    = self::opts();
        $release = $opts['release'] ?? '2025-01';
        $url     = self::API_BASE . "/release/11/{$release}/mms/{$entity_id}/postcoordinationScale";
        return self::request( $url, $lang );
    }

    /* ════════════════════════════════════════
       HELPER: extract entity ID from URI
    ════════════════════════════════════════ */
    public static function uri_to_id( $uri ) {
        $parts = explode( '/', rtrim( $uri, '/' ) );
        return end( $parts );
    }

    /* ════════════════════════════════════════
       HELPER: extract label from @value structure
    ════════════════════════════════════════ */
    public static function label( $field ) {
        if ( is_array( $field ) && isset( $field['@value'] ) ) {
            return $field['@value'];
        }
        if ( is_string( $field ) ) return $field;
        return '';
    }

    /* ════════════════════════════════════════
       TEST CONNECTION
    ════════════════════════════════════════ */
    public static function test_connection() {
        delete_transient( 'hims_icd11_token' );
        $token = self::get_token();
        if ( is_wp_error( $token ) ) return $token;
        // Try fetching root
        $root = self::get_root();
        if ( is_wp_error( $root ) ) return $root;
        return true;
    }
}
