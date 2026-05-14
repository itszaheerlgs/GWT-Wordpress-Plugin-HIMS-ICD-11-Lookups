<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HIMS_ICD11_Public {

    public static function init() {
        $self = new self();
        add_action( 'wp_enqueue_scripts', [ $self, 'enqueue' ] );

        // Shortcodes
        add_shortcode( 'icd11_lookup',   [ $self, 'sc_lookup'   ] );
        add_shortcode( 'icd11_search',   [ $self, 'sc_search'   ] );
        add_shortcode( 'icd11_code',     [ $self, 'sc_code'     ] );
        add_shortcode( 'icd11_chapters', [ $self, 'sc_chapters' ] );
        add_shortcode( 'icd11_history',  [ $self, 'sc_history'  ] );

        // AJAX (logged in + non-logged in)
        $actions = [
            'hims_icd11_search',        'hims_icd11_entity',
            'hims_icd11_codeinfo',      'hims_icd11_children',
            'hims_icd11_chapters',      'hims_icd11_ancestors',
            'hims_icd11_suggest',       'hims_icd11_export_csv',
            'hims_icd11_export_json',   'hims_icd11_history_del',
            'hims_icd11_history_clear', 'hims_icd11_history_get',
        ];
        foreach ( $actions as $action ) {
            add_action( "wp_ajax_{$action}",        [ $self, 'ajax_' . str_replace( 'hims_icd11_', '', $action ) ] );
            add_action( "wp_ajax_nopriv_{$action}", [ $self, 'ajax_' . str_replace( 'hims_icd11_', '', $action ) ] );
        }
    }

    /* ── Enqueue ── */
    public function enqueue() {
        wp_enqueue_style(  'hims-icd11-public', HIMS_ICD11_PLUGIN_URL . 'public/css/public.css', [], HIMS_ICD11_VERSION );
        wp_enqueue_script( 'hims-icd11-public', HIMS_ICD11_PLUGIN_URL . 'public/js/public.js', ['jquery'], HIMS_ICD11_VERSION, true );
        $opts = get_option( HIMS_ICD11_OPTION, [] );
        wp_localize_script( 'hims-icd11-public', 'himsICD11', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'hims_icd11_pub' ),
            'lang'     => $opts['language'] ?? 'en',
            'release'  => $opts['release']  ?? '2025-01',
            'per_page' => $opts['per_page'] ?? 10,
            'i18n'     => [
                'searching'    => __( 'Searching…', 'hims-icd11' ),
                'no_results'   => __( 'No results found.', 'hims-icd11' ),
                'error'        => __( 'An error occurred. Please try again.', 'hims-icd11' ),
                'copy_ok'      => __( 'Copied!', 'hims-icd11' ),
                'loading'      => __( 'Loading…', 'hims-icd11' ),
            ],
        ]);
    }

    /* ════════════════════════════════════════
       SHORTCODE: [icd11_lookup]  — full tool
    ════════════════════════════════════════ */
    public function sc_lookup( $atts ) {
        $a = shortcode_atts([
            'lang'          => '',
            'release'       => '',
            'show_history'  => '1',
            'show_chapters' => '1',
        ], $atts );

        ob_start(); ?>
        <div class="hims-icd11-wrap" data-lang="<?php echo esc_attr($a['lang']); ?>" data-release="<?php echo esc_attr($a['release']); ?>">

            <!-- SEARCH BAR -->
            <div class="hims-search-bar">
                <div class="hims-search-input-wrap">
                    <span class="hims-search-icon">🔍</span>
                    <input type="text" id="hims-search-input" class="hims-search-input"
                        placeholder="<?php esc_attr_e( 'Search ICD-11 codes, diseases, disorders…', 'hims-icd11' ); ?>"
                        autocomplete="off" maxlength="200">
                    <button id="hims-search-clear" class="hims-clear-btn" title="<?php esc_attr_e('Clear','hims-icd11'); ?>">✕</button>
                </div>
                <div class="hims-search-filters">
                    <select id="hims-filter-chapter" class="hims-select">
                        <option value=""><?php _e( 'All Chapters', 'hims-icd11' ); ?></option>
                    </select>
                    <select id="hims-filter-flex" class="hims-select">
                        <option value="false"><?php _e( 'Exact Search', 'hims-icd11' ); ?></option>
                        <option value="true"><?php _e( 'Flexible Search', 'hims-icd11' ); ?></option>
                    </select>
                    <label class="hims-toggle-label">
                        <input type="checkbox" id="hims-filter-keyword" checked>
                        <?php _e( 'Include Keywords', 'hims-icd11' ); ?>
                    </label>
                </div>
                <div id="hims-suggestions" class="hims-suggestions" style="display:none;"></div>
            </div>

            <!-- TABS -->
            <div class="hims-tabs">
                <button class="hims-tab active" data-tab="search"><?php _e( '🔎 Search Results', 'hims-icd11' ); ?></button>
                <?php if ( $a['show_chapters'] ) : ?>
                <button class="hims-tab" data-tab="browse"><?php _e( '📚 Browse Chapters', 'hims-icd11' ); ?></button>
                <?php endif; ?>
                <button class="hims-tab" data-tab="codeinfo"><?php _e( '🔢 Code Info', 'hims-icd11' ); ?></button>
                <?php if ( $a['show_history'] ) : ?>
                <button class="hims-tab" data-tab="history"><?php _e( '🕘 History', 'hims-icd11' ); ?></button>
                <?php endif; ?>
            </div>

            <!-- TAB: SEARCH RESULTS -->
            <div id="hims-tab-search" class="hims-tab-content active">
                <div id="hims-search-status" class="hims-status"></div>
                <div id="hims-search-results" class="hims-results-list"></div>
                <div id="hims-search-pagination" class="hims-pagination"></div>
                <div id="hims-detail-panel" class="hims-detail-panel" style="display:none;"></div>
            </div>

            <!-- TAB: BROWSE -->
            <?php if ( $a['show_chapters'] ) : ?>
            <div id="hims-tab-browse" class="hims-tab-content">
                <div id="hims-chapters-list" class="hims-chapters-grid">
                    <div class="hims-loading"><?php _e( 'Loading chapters…', 'hims-icd11' ); ?></div>
                </div>
                <div id="hims-browse-detail" class="hims-browse-detail" style="display:none;">
                    <button id="hims-browse-back" class="hims-btn hims-btn-sm">← <?php _e('Back','hims-icd11'); ?></button>
                    <div id="hims-browse-content"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TAB: CODE INFO -->
            <div id="hims-tab-codeinfo" class="hims-tab-content">
                <div class="hims-codeinfo-bar">
                    <input type="text" id="hims-code-input" class="hims-search-input"
                        placeholder="<?php esc_attr_e('Enter ICD-11 code or postcoordination string e.g. BA00 or 2C25.Z&XK8G','hims-icd11'); ?>">
                    <button id="hims-code-lookup-btn" class="hims-btn hims-btn-primary"><?php _e('Lookup','hims-icd11'); ?></button>
                </div>
                <div id="hims-codeinfo-result" class="hims-codeinfo-result"></div>
            </div>

            <!-- TAB: HISTORY -->
            <?php if ( $a['show_history'] ) : ?>
            <div id="hims-tab-history" class="hims-tab-content">
                <div class="hims-history-toolbar">
                    <button id="hims-history-clear-btn" class="hims-btn hims-btn-danger hims-btn-sm"><?php _e('Clear History','hims-icd11'); ?></button>
                    <button id="hims-history-export-btn" class="hims-btn hims-btn-sm"><?php _e('Export CSV','hims-icd11'); ?></button>
                </div>
                <div id="hims-history-list" class="hims-history-list"></div>
            </div>
            <?php endif; ?>

        </div>
        <?php return ob_get_clean();
    }

    /* ════════════════════════════════════════
       SHORTCODE: [icd11_search]  — search bar only
    ════════════════════════════════════════ */
    public function sc_search( $atts ) {
        $a = shortcode_atts([
            'lang'        => '',
            'placeholder' => __( 'Search ICD-11…', 'hims-icd11' ),
        ], $atts );

        ob_start(); ?>
        <div class="hims-icd11-wrap hims-search-only" data-lang="<?php echo esc_attr($a['lang']); ?>">
            <div class="hims-search-bar">
                <div class="hims-search-input-wrap">
                    <span class="hims-search-icon">🔍</span>
                    <input type="text" id="hims-search-input" class="hims-search-input"
                        placeholder="<?php echo esc_attr($a['placeholder']); ?>" autocomplete="off">
                    <button id="hims-search-clear" class="hims-clear-btn">✕</button>
                </div>
                <div id="hims-suggestions" class="hims-suggestions" style="display:none;"></div>
            </div>
            <div id="hims-search-status" class="hims-status"></div>
            <div id="hims-search-results" class="hims-results-list"></div>
            <div id="hims-detail-panel" class="hims-detail-panel" style="display:none;"></div>
        </div>
        <?php return ob_get_clean();
    }

    /* ════════════════════════════════════════
       SHORTCODE: [icd11_code code="BA00"]
    ════════════════════════════════════════ */
    public function sc_code( $atts ) {
        $a = shortcode_atts([ 'code' => '', 'lang' => '' ], $atts );
        if ( ! $a['code'] ) return '';

        $data = HIMS_ICD11_API::get_code_info( $a['code'], $a['lang'] ?: null );
        if ( is_wp_error( $data ) ) {
            return '<span class="hims-inline-code hims-error">' . esc_html( $a['code'] ) . '</span>';
        }

        $title = isset($data['stemId']) ? $a['code'] : $a['code'];
        $code  = esc_html( $data['stemCode'] ?? $a['code'] );

        return sprintf(
            '<span class="hims-inline-code" title="%s"><code>%s</code></span>',
            esc_attr( $title ), $code
        );
    }

    /* ════════════════════════════════════════
       SHORTCODE: [icd11_chapters]
    ════════════════════════════════════════ */
    public function sc_chapters( $atts ) {
        $a = shortcode_atts([ 'lang' => '' ], $atts );
        ob_start(); ?>
        <div class="hims-icd11-wrap" data-lang="<?php echo esc_attr($a['lang']); ?>">
            <div id="hims-chapters-list" class="hims-chapters-grid">
                <div class="hims-loading"><?php _e('Loading chapters…','hims-icd11'); ?></div>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    /* ════════════════════════════════════════
       SHORTCODE: [icd11_history]
    ════════════════════════════════════════ */
    public function sc_history( $atts ) {
        $a = shortcode_atts([ 'limit' => 10 ], $atts );
        $records = HIMS_ICD11_History::get( 0, intval( $a['limit'] ) );
        if ( ! $records ) return '<p>' . __( 'No search history yet.', 'hims-icd11' ) . '</p>';

        ob_start(); ?>
        <div class="hims-icd11-wrap">
            <table class="hims-history-table">
                <thead><tr>
                    <th><?php _e('Code','hims-icd11'); ?></th>
                    <th><?php _e('Title','hims-icd11'); ?></th>
                    <th><?php _e('Date','hims-icd11'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $records as $r ) : ?>
                    <tr>
                        <td><code><?php echo esc_html($r->code); ?></code></td>
                        <td><?php echo esc_html($r->title); ?></td>
                        <td><?php echo esc_html($r->searched_at); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php return ob_get_clean();
    }

    /* ════════════════════════════════════════
       AJAX HANDLERS
    ════════════════════════════════════════ */

    private function verify() {
        check_ajax_referer( 'hims_icd11_pub', 'nonce' );
    }

    /* Search */
    public function ajax_search() {
        $this->verify();
        $q    = sanitize_text_field( $_POST['q'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        $args = [
            'useFlexisearch'       => sanitize_text_field( $_POST['flex'] ?? 'false' ),
            'chapterFilter'        => sanitize_text_field( $_POST['chapter'] ?? '' ),
            'includeKeywordResult' => sanitize_text_field( $_POST['keywords'] ?? 'true' ),
            'flatResults'          => 'true',
            'highlightingEnabled'  => 'true',
            'medicalCodingMode'    => 'true',
        ];
        if ( $lang ) $args['lang'] = $lang;

        $result = HIMS_ICD11_API::search( $q, $args );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success( $result );
    }

    /* Entity detail */
    public function ajax_entity() {
        $this->verify();
        $id   = sanitize_text_field( $_POST['entity_id'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing entity_id' );

        $result = HIMS_ICD11_API::get_entity( $id, $lang ?: null );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        // Save to history
        $code  = $result['code'] ?? $id;
        $title = isset($result['title']['@value']) ? $result['title']['@value'] : ( $result['title'] ?? $id );
        HIMS_ICD11_History::add( $code, $title );

        wp_send_json_success( $result );
    }

    /* Code info */
    public function ajax_codeinfo() {
        $this->verify();
        $code = sanitize_text_field( $_POST['code'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        if ( ! $code ) wp_send_json_error( 'Missing code' );

        $result = HIMS_ICD11_API::get_code_info( $code, $lang ?: null );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        // Also get entity details for the stem
        $entity_data = null;
        if ( ! empty( $result['stemId'] ) ) {
            $eid = HIMS_ICD11_API::uri_to_id( $result['stemId'] );
            $entity_data = HIMS_ICD11_API::get_entity( $eid, $lang ?: null );
            if ( is_wp_error( $entity_data ) ) $entity_data = null;
        }

        wp_send_json_success([ 'codeinfo' => $result, 'entity' => $entity_data ]);
    }

    /* Children of entity */
    public function ajax_children() {
        $this->verify();
        $id   = sanitize_text_field( $_POST['entity_id'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing entity_id' );

        $result = HIMS_ICD11_API::get_children( $id, $lang ?: null );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success( $result );
    }

    /* Chapters (root) */
    public function ajax_chapters() {
        $this->verify();
        $lang   = sanitize_text_field( $_POST['lang'] ?? '' );
        $result = HIMS_ICD11_API::get_root( $lang ?: null );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success( $result );
    }

    /* Ancestors / breadcrumb */
    public function ajax_ancestors() {
        $this->verify();
        $id   = sanitize_text_field( $_POST['entity_id'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'Missing entity_id' );

        $result = HIMS_ICD11_API::get_ancestors( $id, $lang ?: null );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success( $result );
    }

    /* Autocomplete suggestions */
    public function ajax_suggest() {
        $this->verify();
        $q    = sanitize_text_field( $_POST['q'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        if ( strlen($q) < 2 ) wp_send_json_success([]);
        $result = HIMS_ICD11_API::search( $q, [
            'useFlexisearch'      => 'false',
            'flatResults'         => 'true',
            'highlightingEnabled' => 'false',
            'lang'                => $lang,
        ]);
        if ( is_wp_error( $result ) ) wp_send_json_success([]);
        $items = array_slice( $result['destinationEntities'] ?? [], 0, 8 );
        wp_send_json_success( $items );
    }

    /* Export search results as CSV */
    public function ajax_export_csv() {
        $this->verify();
        $q    = sanitize_text_field( $_POST['q'] ?? '' );
        $lang = sanitize_text_field( $_POST['lang'] ?? '' );
        $result = HIMS_ICD11_API::search( $q, [ 'flatResults' => 'true', 'lang' => $lang ] );

        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        $rows   = $result['destinationEntities'] ?? [];
        $output = [];
        $output[] = [ 'Code', 'Title', 'Chapter', 'URL' ];
        foreach ( $rows as $r ) {
            $output[] = [
                $r['theCode'] ?? '',
                strip_tags( $r['title'] ?? '' ),
                $r['chapter'] ?? '',
                $r['id'] ?? '',
            ];
        }

        $csv = '';
        foreach ( $output as $row ) {
            $csv .= implode( ',', array_map( fn($v) => '"' . str_replace('"','""',$v) . '"', $row ) ) . "\n";
        }

        wp_send_json_success([ 'csv' => $csv, 'filename' => 'icd11-' . sanitize_title($q) . '-' . date('Ymd') . '.csv' ]);
    }

    /* Export search results as JSON */
    public function ajax_export_json() {
        $this->verify();
        $q      = sanitize_text_field( $_POST['q'] ?? '' );
        $lang   = sanitize_text_field( $_POST['lang'] ?? '' );
        $result = HIMS_ICD11_API::search( $q, [ 'flatResults' => 'true', 'lang' => $lang ] );
        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
        wp_send_json_success([ 'json' => json_encode( $result, JSON_PRETTY_PRINT ), 'filename' => 'icd11-' . sanitize_title($q) . '-' . date('Ymd') . '.json' ]);
    }

    /* History: get */
    public function ajax_history_get() {
        $this->verify();
        $records = HIMS_ICD11_History::get( 0, 50 );
        $data = array_map( fn($r) => [
            'id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'date' => $r->searched_at
        ], $records );
        wp_send_json_success( $data );
    }

    /* History: delete one */
    public function ajax_history_del() {
        $this->verify();
        $id = intval( $_POST['record_id'] ?? 0 );
        HIMS_ICD11_History::delete( $id );
        wp_send_json_success();
    }

    /* History: clear all */
    public function ajax_history_clear() {
        $this->verify();
        HIMS_ICD11_History::clear();
        wp_send_json_success();
    }
}
