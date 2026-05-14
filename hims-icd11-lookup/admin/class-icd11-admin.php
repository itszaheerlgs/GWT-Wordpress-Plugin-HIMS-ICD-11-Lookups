<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HIMS_ICD11_Admin {

    public static function init() {
        $self = new self();
        add_action( 'admin_menu',            [ $self, 'add_menu' ] );
        add_action( 'admin_init',            [ $self, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $self, 'enqueue' ] );
        add_action( 'wp_ajax_hims_icd11_test',        [ $self, 'ajax_test' ] );
        add_action( 'wp_ajax_hims_icd11_flush_cache', [ $self, 'ajax_flush_cache' ] );
        add_action( 'wp_ajax_hims_icd11_clear_history', [ $self, 'ajax_clear_history' ] );
        add_action( 'wp_ajax_hims_icd11_delete_history', [ $self, 'ajax_delete_history' ] );
    }

    /* ── Menu ── */
    public function add_menu() {
        add_menu_page(
            __( 'ICD-11 Lookup', 'hims-icd11' ),
            __( 'ICD-11', 'hims-icd11' ),
            'manage_options',
            'hims-icd11',
            [ $this, 'page_settings' ],
            'dashicons-heart',
            30
        );
        add_submenu_page( 'hims-icd11', __( 'Settings', 'hims-icd11' ),    __( 'Settings', 'hims-icd11' ),    'manage_options', 'hims-icd11',         [ $this, 'page_settings'  ] );
        add_submenu_page( 'hims-icd11', __( 'Cache', 'hims-icd11' ),       __( 'Cache', 'hims-icd11' ),       'manage_options', 'hims-icd11-cache',   [ $this, 'page_cache'     ] );
        add_submenu_page( 'hims-icd11', __( 'Search History', 'hims-icd11' ), __( 'History', 'hims-icd11' ),  'manage_options', 'hims-icd11-history', [ $this, 'page_history'   ] );
        add_submenu_page( 'hims-icd11', __( 'Shortcodes', 'hims-icd11' ),  __( 'Shortcodes', 'hims-icd11' ), 'manage_options', 'hims-icd11-sc',      [ $this, 'page_shortcodes'] );
    }

    /* ── Settings ── */
    public function register_settings() {
        register_setting( 'hims_icd11_group', HIMS_ICD11_OPTION, [ $this, 'sanitize_settings' ] );

        add_settings_section( 'hims_icd11_api', __( 'WHO ICD-API Credentials', 'hims-icd11' ), '__return_false', 'hims-icd11' );
        add_settings_section( 'hims_icd11_gen', __( 'General Settings', 'hims-icd11' ),         '__return_false', 'hims-icd11' );

        $fields = [
            [ 'client_id',     __( 'Client ID', 'hims-icd11' ),           'hims_icd11_api', 'text'   ],
            [ 'client_secret', __( 'Client Secret', 'hims-icd11' ),       'hims_icd11_api', 'password'],
            [ 'release',       __( 'Release Version', 'hims-icd11' ),     'hims_icd11_gen', 'text'   ],
            [ 'language',      __( 'Default Language', 'hims-icd11' ),    'hims_icd11_gen', 'text'   ],
            [ 'cache_ttl',     __( 'Cache TTL (seconds)', 'hims-icd11' ), 'hims_icd11_gen', 'number' ],
            [ 'per_page',      __( 'Results per Page', 'hims-icd11' ),    'hims_icd11_gen', 'number' ],
        ];

        foreach ( $fields as [$key, $label, $section, $type] ) {
            add_settings_field(
                "hims_icd11_{$key}", $label,
                [ $this, 'render_field' ],
                'hims-icd11', $section,
                [ 'key' => $key, 'type' => $type ]
            );
        }
    }

    public function render_field( $args ) {
        $opts = get_option( HIMS_ICD11_OPTION, [] );
        $key  = $args['key'];
        $type = $args['type'];
        $val  = $opts[$key] ?? '';
        echo "<input type='{$type}' name='" . HIMS_ICD11_OPTION . "[{$key}]' value='" . esc_attr( $val ) . "' class='regular-text'>";
    }

    public function sanitize_settings( $input ) {
        $clean = [];
        $clean['client_id']     = sanitize_text_field( $input['client_id'] ?? '' );
        $clean['client_secret'] = sanitize_text_field( $input['client_secret'] ?? '' );
        $clean['release']       = sanitize_text_field( $input['release'] ?? '2025-01' );
        $clean['language']      = sanitize_text_field( $input['language'] ?? 'en' );
        $clean['cache_ttl']     = intval( $input['cache_ttl'] ?? 3600 );
        $clean['per_page']      = intval( $input['per_page'] ?? 10 );
        delete_transient( 'hims_icd11_token' );
        return $clean;
    }

    /* ── Enqueue admin assets ── */
    public function enqueue( $hook ) {
        if ( strpos( $hook, 'hims-icd11' ) === false ) return;
        wp_enqueue_style( 'hims-icd11-admin', HIMS_ICD11_PLUGIN_URL . 'admin/admin.css', [], HIMS_ICD11_VERSION );
        wp_enqueue_script( 'hims-icd11-admin', HIMS_ICD11_PLUGIN_URL . 'admin/admin.js', ['jquery'], HIMS_ICD11_VERSION, true );
        wp_localize_script( 'hims-icd11-admin', 'himsICD11Admin', [
            'nonce'    => wp_create_nonce( 'hims_icd11_admin' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ]);
    }

    /* ── AJAX: Test connection ── */
    public function ajax_test() {
        check_ajax_referer( 'hims_icd11_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $result = HIMS_ICD11_API::test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( __( 'Connection successful! ICD-11 API is working.', 'hims-icd11' ) );
    }

    /* ── AJAX: Flush cache ── */
    public function ajax_flush_cache() {
        check_ajax_referer( 'hims_icd11_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        HIMS_ICD11_Cache::flush_all();
        wp_send_json_success( __( 'Cache cleared successfully.', 'hims-icd11' ) );
    }

    /* ── AJAX: Clear all history ── */
    public function ajax_clear_history() {
        check_ajax_referer( 'hims_icd11_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}icd11_history" );
        wp_send_json_success( __( 'History cleared.', 'hims-icd11' ) );
    }

    /* ── AJAX: Delete one history record ── */
    public function ajax_delete_history() {
        check_ajax_referer( 'hims_icd11_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        $id = intval( $_POST['record_id'] ?? 0 );
        HIMS_ICD11_History::delete( $id, 0 );
        wp_send_json_success();
    }

    /* ════════════════════════════════════════
       PAGE: SETTINGS
    ════════════════════════════════════════ */
    public function page_settings() { ?>
        <div class="wrap hims-admin-wrap">
            <h1 class="hims-admin-title">
                <span class="dashicons dashicons-heart"></span>
                <?php _e( 'HIMS ICD-11 Lookup — Settings', 'hims-icd11' ); ?>
            </h1>
            <div class="hims-admin-grid">
                <div class="hims-admin-main">
                    <form method="post" action="options.php">
                        <?php settings_fields( 'hims_icd11_group' ); ?>
                        <?php do_settings_sections( 'hims-icd11' ); ?>
                        <p class="submit">
                            <?php submit_button( __( 'Save Settings', 'hims-icd11' ), 'primary', 'submit', false ); ?>
                            <button type="button" id="hims-test-btn" class="button button-secondary" style="margin-left:10px;">
                                <?php _e( 'Test API Connection', 'hims-icd11' ); ?>
                            </button>
                        </p>
                        <div id="hims-test-result" class="hims-notice" style="display:none;"></div>
                    </form>
                </div>
                <div class="hims-admin-sidebar">
                    <div class="hims-card">
                        <h3><?php _e( 'How to Get API Keys', 'hims-icd11' ); ?></h3>
                        <ol>
                            <li><?php printf( __( 'Register at <a href="%s" target="_blank">icd.who.int/icdapi</a>', 'hims-icd11' ), 'https://icd.who.int/icdapi' ); ?></li>
                            <li><?php _e( 'Login and click "View API access key"', 'hims-icd11' ); ?></li>
                            <li><?php _e( 'Copy Client ID and Client Secret here', 'hims-icd11' ); ?></li>
                        </ol>
                        <a href="https://icd.who.int/icdapi" target="_blank" class="button button-primary"><?php _e( 'Get API Keys →', 'hims-icd11' ); ?></a>
                    </div>
                    <div class="hims-card">
                        <h3><?php _e( 'Supported Languages', 'hims-icd11' ); ?></h3>
                        <code>en, ar, zh, fr, de, hi, id, it, ja, ko, pt, ru, es, tr, uk</code>
                    </div>
                    <div class="hims-card">
                        <h3><?php _e( 'Available Releases', 'hims-icd11' ); ?></h3>
                        <code>2025-01, 2024-01, 2023-01, 2022-02, 2021-05, 2020-09, 2019-04</code>
                    </div>
                </div>
            </div>
        </div>
    <?php }

    /* ════════════════════════════════════════
       PAGE: CACHE
    ════════════════════════════════════════ */
    public function page_cache() {
        $count = HIMS_ICD11_Cache::count(); ?>
        <div class="wrap hims-admin-wrap">
            <h1 class="hims-admin-title"><span class="dashicons dashicons-database"></span> <?php _e( 'Cache Management', 'hims-icd11' ); ?></h1>
            <div class="hims-card" style="max-width:600px;">
                <p><?php printf( __( 'Currently <strong>%d cached entries</strong> stored in WordPress transients.', 'hims-icd11' ), $count ); ?></p>
                <button id="hims-flush-btn" class="button button-secondary"><?php _e( 'Flush All Cache', 'hims-icd11' ); ?></button>
                <div id="hims-flush-result" class="hims-notice" style="display:none; margin-top:10px;"></div>
            </div>
        </div>
    <?php }

    /* ════════════════════════════════════════
       PAGE: HISTORY
    ════════════════════════════════════════ */
    public function page_history() {
        $page    = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $limit   = 20;
        $offset  = ( $page - 1 ) * $limit;
        $records = HIMS_ICD11_History::get_all( $limit, $offset );
        $total   = HIMS_ICD11_History::count_all();
        $pages   = ceil( $total / $limit ); ?>
        <div class="wrap hims-admin-wrap">
            <h1 class="hims-admin-title"><span class="dashicons dashicons-backup"></span> <?php _e( 'Search History', 'hims-icd11' ); ?>
                <button id="hims-clear-history-btn" class="button button-secondary" style="float:right;"><?php _e( 'Clear All History', 'hims-icd11' ); ?></button>
            </h1>
            <p><?php printf( __( 'Total: <strong>%d</strong> records', 'hims-icd11' ), $total ); ?></p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php _e( 'Code', 'hims-icd11' ); ?></th>
                    <th><?php _e( 'Title', 'hims-icd11' ); ?></th>
                    <th><?php _e( 'User', 'hims-icd11' ); ?></th>
                    <th><?php _e( 'Date', 'hims-icd11' ); ?></th>
                    <th><?php _e( 'Actions', 'hims-icd11' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( $records ) : foreach ( $records as $r ) : ?>
                    <tr id="hims-history-row-<?php echo $r->id; ?>">
                        <td><code><?php echo esc_html( $r->code ); ?></code></td>
                        <td><?php echo esc_html( $r->title ); ?></td>
                        <td><?php echo esc_html( $r->display_name ?: '—' ); ?></td>
                        <td><?php echo esc_html( $r->searched_at ); ?></td>
                        <td><button class="button button-small hims-del-history" data-id="<?php echo $r->id; ?>"><?php _e( 'Delete', 'hims-icd11' ); ?></button></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="5"><?php _e( 'No history yet.', 'hims-icd11' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <?php echo paginate_links([ 'base' => add_query_arg('paged','%#%'), 'format' => '', 'current' => $page, 'total' => $pages ]); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php }

    /* ════════════════════════════════════════
       PAGE: SHORTCODES
    ════════════════════════════════════════ */
    public function page_shortcodes() { ?>
        <div class="wrap hims-admin-wrap">
            <h1 class="hims-admin-title"><span class="dashicons dashicons-shortcode"></span> <?php _e( 'Available Shortcodes', 'hims-icd11' ); ?></h1>
            <div class="hims-card">
                <table class="wp-list-table widefat">
                    <thead><tr><th><?php _e('Shortcode','hims-icd11');?></th><th><?php _e('Description','hims-icd11');?></th><th><?php _e('Attributes','hims-icd11');?></th></tr></thead>
                    <tbody>
                    <tr>
                        <td><code>[icd11_lookup]</code></td>
                        <td><?php _e('Full search + browse + detail tool','hims-icd11');?></td>
                        <td><code>lang="en" release="2025-01" show_history="1" show_chapters="1"</code></td>
                    </tr>
                    <tr>
                        <td><code>[icd11_search]</code></td>
                        <td><?php _e('Search bar only','hims-icd11');?></td>
                        <td><code>lang="en" placeholder="Search ICD-11…"</code></td>
                    </tr>
                    <tr>
                        <td><code>[icd11_code]</code></td>
                        <td><?php _e('Display a single ICD-11 code inline','hims-icd11');?></td>
                        <td><code>code="BA00" lang="en"</code></td>
                    </tr>
                    <tr>
                        <td><code>[icd11_chapters]</code></td>
                        <td><?php _e('Browse all 26 ICD-11 chapters','hims-icd11');?></td>
                        <td><code>lang="en"</code></td>
                    </tr>
                    <tr>
                        <td><code>[icd11_history]</code></td>
                        <td><?php _e('Show current user\'s search history','hims-icd11');?></td>
                        <td><code>limit="10"</code></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php }
}
