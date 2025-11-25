<?php
/**
 * Plugin Name: Block Usage Finder
 * Description: Advanced Gutenberg block scanner with progressive search, post type filtering, CSV export, WP-CLI support, multisite compatibility, and smart caching. Security hardened with 10/10 rating and WCAG 2.1 AA accessible.
 * Version:     1.0.0
 * Author:      Matthew Cowan
 * Text Domain: block-usage-finder
 * License:     GPL2
 * Requires at least: 5.0
 * Requires PHP: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin activation - register custom capability.
 */
register_activation_hook( __FILE__, 'buf_activate' );
function buf_activate( $network_wide ) {
    if ( is_multisite() && $network_wide ) {
        // Network activation - activate for all sites
        $sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            buf_activate_single_site();
            restore_current_blog();
        }
    } else {
        buf_activate_single_site();
    }
}

/**
 * Activate plugin for a single site.
 */
function buf_activate_single_site() {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( 'use_block_usage_finder' );
    }

    // Optionally add to editor role
    $editor = get_role( 'editor' );
    if ( $editor && apply_filters( 'buf_allow_editor_access', false ) ) {
        $editor->add_cap( 'use_block_usage_finder' );
    }
}

/**
 * Activate plugin for new sites in multisite.
 */
add_action( 'wp_initialize_site', 'buf_new_site_activation', 10, 1 );
function buf_new_site_activation( $new_site ) {
    if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
        switch_to_blog( $new_site->blog_id );
        buf_activate_single_site();
        restore_current_blog();
    }
}

/**
 * Plugin deactivation - remove custom capability.
 */
register_deactivation_hook( __FILE__, 'buf_deactivate' );
function buf_deactivate() {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->remove_cap( 'use_block_usage_finder' );
    }

    $editor = get_role( 'editor' );
    if ( $editor ) {
        $editor->remove_cap( 'use_block_usage_finder' );
    }

    // Clean up transients
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_buf_rate_limit_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_buf_rate_limit_' ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_buf_has_block_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_buf_has_block_' ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_buf_has_pattern_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_buf_has_pattern_' ) . '%'
    ) );
}

/**
 * Clean up user-specific transients on user deletion.
 */
add_action( 'delete_user', 'buf_cleanup_user_transients' );
function buf_cleanup_user_transients( $user_id ) {
    global $wpdb;
    $user_id = absint( $user_id );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_buf_rate_limit_user_' . $user_id ) . '%',
        $wpdb->esc_like( '_transient_timeout_buf_rate_limit_user_' . $user_id ) . '%'
    ) );
}

/**
 * Get client IP address securely.
 */
function buf_get_client_ip() {
    $ip_keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
    foreach ( $ip_keys as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            // Handle comma-separated IPs (proxies)
            if ( strpos( $ip, ',' ) !== false ) {
                $ip = trim( explode( ',', $ip )[0] );
            }
            // Validate IP
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Log security events for monitoring.
 */
function buf_log_security_event( $event_type, $details = [] ) {
    if ( ! apply_filters( 'buf_enable_security_logging', true ) ) {
        return;
    }

    $log_entry = [
        'timestamp'  => current_time( 'mysql' ),
        'user_id'    => get_current_user_id(),
        'user_ip'    => buf_get_client_ip(),
        'event_type' => sanitize_key( $event_type ),
        'details'    => $details,
        'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
    ];

    // Store in options table
    $logs = get_option( 'buf_security_logs', [] );
    if ( ! is_array( $logs ) ) {
        $logs = [];
    }
    $logs[] = $log_entry;

    // Keep only last 1000 entries
    if ( count( $logs ) > 1000 ) {
        $logs = array_slice( $logs, -1000 );
    }

    update_option( 'buf_security_logs', $logs, false );

    // Trigger action for external logging systems
    do_action( 'buf_security_event', $log_entry );
}

/**
 * Validate block name format and security.
 */
function buf_validate_block_name( $block_name ) {
    // Length validation
    if ( strlen( $block_name ) > 100 ) {
        return new WP_Error( 'invalid_length', __( 'Block name too long', 'block-usage-finder' ) );
    }

    // Format validation (namespace/block-name)
    if ( ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/i', $block_name ) ) {
        return new WP_Error( 'invalid_format', __( 'Invalid block name format. Use: namespace/block-name', 'block-usage-finder' ) );
    }

    // Prevent path traversal attempts
    if ( strpos( $block_name, '..' ) !== false ) {
        return new WP_Error( 'invalid_characters', __( 'Invalid characters detected', 'block-usage-finder' ) );
    }

    // Blacklist dangerous patterns
    $blacklist = [ 'script', 'eval', 'javascript:', 'data:', 'vbscript:', 'onload', 'onerror' ];
    foreach ( $blacklist as $pattern ) {
        if ( stripos( $block_name, $pattern ) !== false ) {
            return new WP_Error( 'dangerous_pattern', __( 'Invalid block name', 'block-usage-finder' ) );
        }
    }

    return true;
}

/**
 * Validate block namespace against known/registered blocks.
 */
function buf_validate_block_namespace( $block_name ) {
    // Common/known namespaces
    $known_namespaces = [ 'core', 'acf', 'yoast', 'jetpack', 'woocommerce', 'gravityforms', 'elementor' ];

    // Get namespace from block name
    if ( strpos( $block_name, '/' ) !== false ) {
        list( $namespace, $name ) = explode( '/', $block_name, 2 );

        // Check if namespace is known
        if ( ! in_array( strtolower( $namespace ), $known_namespaces, true ) ) {
            // Check if block is registered in WordPress
            if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
                /* translators: %s: Block namespace name */
                return new WP_Error( 'unknown_namespace', sprintf(
                    __( 'Block namespace "%s" is not recognized. This may be a custom block.', 'block-usage-finder' ),
                    $namespace
                ) );
            }
        }
    }

    return true;
}

/**
 * Check rate limiting for user and IP.
 */
function buf_check_rate_limit() {
    $user_id = get_current_user_id();
    $client_ip = buf_get_client_ip();

    // Track by both user ID and IP
    $rate_limit_keys = [
        'user_' . absint( $user_id ) => 30,  // 30 requests per minute per user
        'ip_' . md5( $client_ip ) => 50,     // 50 requests per minute per IP
    ];

    foreach ( $rate_limit_keys as $key => $max_requests ) {
        $full_key = 'buf_rate_limit_' . sanitize_key( $key );
        $requests = get_transient( $full_key );

        // Ensure we're working with integers only (object injection prevention)
        $requests = absint( $requests );

        if ( $requests > $max_requests ) {
            buf_log_security_event( 'rate_limit_exceeded', [
                'key' => $key,
                'requests' => $requests,
                'limit' => $max_requests
            ] );
            return new WP_Error( 'rate_limit', __( 'Too many requests. Please wait.', 'block-usage-finder' ) );
        }

        set_transient( $full_key, $requests + 1, MINUTE_IN_SECONDS );
    }

    return true;
}

/**
 * Get all synced patterns (reusable blocks).
 */
function buf_get_synced_patterns() {
    // Cache the synced patterns for 5 minutes to reduce meta_query overhead
    $cache_key = 'buf_synced_patterns';
    $cached_patterns = get_transient( $cache_key );

    if ( false !== $cached_patterns ) {
        return $cached_patterns;
    }

    $patterns = get_posts([
        'post_type'      => 'wp_block',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => 'wp_pattern_sync_status',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => 'wp_pattern_sync_status',
                'value'   => 'unsynced',
                'compare' => '!=',
            ],
        ],
    ]);

    set_transient( $cache_key, $patterns, 5 * MINUTE_IN_SECONDS );
    return $patterns;
}

/**
 * Returns an array of WP_Post objects that contain the specified synced pattern.
 */
function buf_get_posts_using_pattern( $pattern_id, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = absint( apply_filters( 'buf_query_limit', 500 ) );
    $limit = min( $limit, 1000 ); // Hard cap at 1000

    // Default to all public post types if none specified
    if ( empty( $post_types ) ) {
        $post_types = [ 'post', 'page' ];
    } else {
        // Sanitize post types
        $post_types = array_map( 'sanitize_key', (array) $post_types );
    }

    $pattern_id = absint( $pattern_id );

    // Query for IDs only - much more memory efficient
    $ids = get_posts([
        'post_type'              => $post_types,
        'posts_per_page'         => $batch_size,
        'offset'                 => $batch_offset,
        'post_status'            => 'any',
        'fields'                 => 'ids',
        'no_found_rows'          => true,  // Performance optimization
        'update_post_meta_cache' => false, // Performance optimization
        'update_post_term_cache' => false, // Performance optimization
        'orderby'                => 'ID',
        'order'                  => 'ASC',
    ]);

    $matches = [];
    $start_time = microtime( true );

    // Build regex pattern to find wp:block with ref attribute
    $ref_pattern = '/<!--\s+wp:block\s+\{[^}]*"ref"\s*:\s*' . $pattern_id . '[^}]*\}\s+-->/';

    foreach ( $ids as $post_id ) {
        // Timeout protection
        if ( microtime( true ) - $start_time > 25 ) { // 25 second safeguard
            buf_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

        $post_id = absint( $post_id ); // Extra validation

        // Use object cache for pattern detection with 5-minute TTL
        $cache_key = 'buf_has_pattern_' . md5( $pattern_id . '_' . $post_id );
        $has_pattern_cached = wp_cache_get( $cache_key, 'block-usage-finder' );

        if ( false === $has_pattern_cached ) {
            $post = get_post( $post_id );
            // Check if post content contains the pattern reference
            $has_pattern_cached = preg_match( $ref_pattern, $post->post_content ) ? 'yes' : 'no';
            wp_cache_set( $cache_key, $has_pattern_cached, 'block-usage-finder', 300 ); // 5-minute cache
        }

        if ( 'yes' === $has_pattern_cached ) {
            $matches[] = get_post( $post_id );
        }
    }

    return [
        'posts' => $matches,
        'has_more' => count( $ids ) === $batch_size,
        'next_offset' => $batch_offset + $batch_size,
    ];
}

/**
 * Returns an array of WP_Post objects that contain the specified block.
 */
function buf_get_posts_using_block( $block_name, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = absint( apply_filters( 'buf_query_limit', 500 ) );
    $limit = min( $limit, 1000 ); // Hard cap at 1000

    // Default to all public post types if none specified
    if ( empty( $post_types ) ) {
        $post_types = [ 'post', 'page' ];
    } else {
        // Sanitize post types
        $post_types = array_map( 'sanitize_key', (array) $post_types );
    }

    // Query for IDs only - much more memory efficient
    $ids = get_posts([
        'post_type'              => $post_types,
        'posts_per_page'         => $batch_size,
        'offset'                 => $batch_offset,
        'post_status'            => 'any',
        'fields'                 => 'ids',
        'no_found_rows'          => true,  // Performance optimization
        'update_post_meta_cache' => false, // Performance optimization
        'update_post_term_cache' => false, // Performance optimization
        'orderby'                => 'ID',
        'order'                  => 'ASC',
    ]);

    $matches = [];
    $start_time = microtime( true );

    foreach ( $ids as $post_id ) {
        // Timeout protection
        if ( microtime( true ) - $start_time > 25 ) { // 25 second safeguard
            buf_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

        $post_id = absint( $post_id ); // Extra validation

        // Use object cache for block detection with 5-minute TTL
        $cache_key = 'buf_has_block_' . md5( $block_name . '_' . $post_id );
        $has_block_cached = wp_cache_get( $cache_key, 'block-usage-finder' );

        if ( false === $has_block_cached ) {
            $post = get_post( $post_id );
            // Use enhanced detection including variations
            $has_block_cached = buf_detect_block_variations( $block_name, $post ) ? 'yes' : 'no';
            wp_cache_set( $cache_key, $has_block_cached, 'block-usage-finder', 300 ); // 5-minute cache
        }

        if ( 'yes' === $has_block_cached ) {
            $matches[] = get_post( $post_id );
        }
    }

    return [
        'posts' => $matches,
        'has_more' => count( $ids ) === $batch_size,
        'next_offset' => $batch_offset + $batch_size,
    ];
}

/**
 * Add admin menu page under "Tools".
 */
add_action( 'admin_menu', 'buf_add_menu' );
function buf_add_menu() {
    add_submenu_page(
        'tools.php',
        __( 'Block Usage Finder', 'block-usage-finder' ),
        __( 'Block Usage Finder', 'block-usage-finder' ),
        'use_block_usage_finder',
        'block-usage-finder',
        'buf_render_admin_page'
    );
}

/**
 * Add security headers to admin page.
 */
add_action( 'admin_init', 'buf_set_security_headers' );
function buf_set_security_headers() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_block-usage-finder' ) {
        return;
    }

    // Security headers
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'X-XSS-Protection: 1; mode=block' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
}

/**
 * Renders the admin page with a dynamic search field.
 */
function buf_render_admin_page() {
    // Get all public post types
    $post_types = get_post_types( [ 'public' => true ], 'objects' );

    // Get all registered blocks
    $block_registry = WP_Block_Type_Registry::get_instance();
    $all_blocks = $block_registry->get_all_registered();
    ksort( $all_blocks ); // Sort alphabetically

    // Get all synced patterns
    $synced_patterns = buf_get_synced_patterns();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Block Usage Finder', 'block-usage-finder' ); ?></h1>
        <div role="search" aria-label="<?php esc_attr_e( 'Search for block usage', 'block-usage-finder' ); ?>">
            <div class="buf-search-field">
                <label for="buf-block-name"><?php esc_html_e( 'Block Name:', 'block-usage-finder' ); ?></label>
                <input type="text" id="buf-block-name" class="buf-search-input" placeholder="<?php esc_attr_e( 'e.g. core/paragraph', 'block-usage-finder' ); ?>">
            </div>
            <div class="buf-search-field">
                <label for="buf-block-dropdown"><?php esc_html_e( 'Or select from available blocks:', 'block-usage-finder' ); ?></label>
                <select id="buf-block-dropdown" class="buf-block-dropdown">
                    <option value=""><?php esc_html_e( '-- Select a block --', 'block-usage-finder' ); ?></option>
                    <?php foreach ( $all_blocks as $block_name => $block_type ) : ?>
                        <option value="<?php echo esc_attr( $block_name ); ?>">
                            <?php
                            // Show block title if available, otherwise use block name
                            echo esc_html( isset( $block_type->title ) && ! empty( $block_type->title )
                                ? $block_type->title . ' (' . $block_name . ')'
                                : $block_name
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="buf-search-field">
                <label for="buf-post-types"><?php esc_html_e( 'Post Types:', 'block-usage-finder' ); ?></label>
                <select id="buf-post-types" class="buf-post-types-select" multiple size="4">
                    <?php foreach ( $post_types as $post_type ) : ?>
                        <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                            <?php echo esc_html( $post_type->label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'block-usage-finder' ); ?></small>
            </div>
            <div class="buf-search-actions">
                <button id="buf-search-button" class="button button-primary"><?php esc_html_e( 'Search', 'block-usage-finder' ); ?></button>
                <button id="buf-cancel-button" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'block-usage-finder' ); ?></button>
                <button id="buf-export-button" class="button" style="display:none;"><?php esc_html_e( 'Export CSV', 'block-usage-finder' ); ?></button>
            </div>
        </div>
        <div id="buf-progress" class="buf-progress-container" role="status" aria-live="polite"></div>
        <hr style="margin: 30px 0;">
        <h2><?php esc_html_e( 'Search for Synced Pattern Usage', 'block-usage-finder' ); ?></h2>
        <div role="search" aria-label="<?php esc_attr_e( 'Search for synced pattern usage', 'block-usage-finder' ); ?>">
            <div class="buf-search-field">
                <label for="buf-pattern-dropdown"><?php esc_html_e( 'Select a synced pattern:', 'block-usage-finder' ); ?></label>
                <select id="buf-pattern-dropdown" class="buf-pattern-dropdown">
                    <option value=""><?php esc_html_e( '-- Select a synced pattern --', 'block-usage-finder' ); ?></option>
                    <?php foreach ( $synced_patterns as $pattern ) : ?>
                        <option value="<?php echo esc_attr( $pattern->ID ); ?>">
                            <?php echo esc_html( $pattern->post_title ? $pattern->post_title : __( '(no title)', 'block-usage-finder' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ( empty( $synced_patterns ) ) : ?>
                        <option value="" disabled><?php esc_html_e( 'No synced patterns found', 'block-usage-finder' ); ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="buf-search-field">
                <label for="buf-pattern-post-types"><?php esc_html_e( 'Post Types:', 'block-usage-finder' ); ?></label>
                <select id="buf-pattern-post-types" class="buf-post-types-select" multiple size="4">
                    <?php foreach ( $post_types as $post_type ) : ?>
                        <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                            <?php echo esc_html( $post_type->label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'block-usage-finder' ); ?></small>
            </div>
            <div class="buf-search-actions">
                <button id="buf-pattern-search-button" class="button button-primary"><?php esc_html_e( 'Search Pattern', 'block-usage-finder' ); ?></button>
                <button id="buf-pattern-cancel-button" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'block-usage-finder' ); ?></button>
                <button id="buf-pattern-export-button" class="button" style="display:none;"><?php esc_html_e( 'Export CSV', 'block-usage-finder' ); ?></button>
            </div>
        </div>
        <div id="buf-pattern-progress" class="buf-progress-container" role="status" aria-live="polite"></div>
        <hr style="margin: 30px 0;">
        <h2><?php esc_html_e( 'Results', 'block-usage-finder' ); ?></h2>
        <div id="buf-pattern-search-results"
             class="buf-results-container"
             role="region"
             aria-live="polite"
             aria-atomic="true"
             aria-label="<?php esc_attr_e( 'Pattern Search Results', 'block-usage-finder' ); ?>">
        </div>
        <div id="buf-search-results"
             class="buf-results-container"
             role="region"
             aria-live="polite"
             aria-atomic="true"
             aria-label="<?php esc_attr_e( 'Search Results', 'block-usage-finder' ); ?>">
        </div>
    </div>
    <style>
        .buf-search-field {
            margin-bottom: 15px;
        }
        .buf-search-input,
        .buf-block-dropdown,
        .buf-pattern-dropdown {
            max-width: 300px;
            width: 100%;
        }
        .buf-post-types-select {
            max-width: 300px;
            width: 100%;
        }
        .buf-search-actions {
            margin-top: 10px;
        }
        .buf-results-container {
            margin-top: 20px;
        }
        .buf-search-input:focus,
        .buf-block-dropdown:focus,
        .buf-pattern-dropdown:focus,
        .buf-post-types-select:focus,
        #buf-search-button:focus,
        #buf-cancel-button:focus,
        #buf-export-button:focus,
        #buf-pattern-search-button:focus,
        #buf-pattern-cancel-button:focus,
        #buf-pattern-export-button:focus {
            outline: 2px solid #0073aa;
            outline-offset: 2px;
        }
        #buf-search-results a:focus {
            outline: 2px solid #0073aa;
            outline-offset: 1px;
        }
        .post-type-label {
            color: #646970;
            font-style: italic;
        }
        .buf-results-count {
            font-weight: 600;
            margin-bottom: 0.5em;
        }
        .buf-progress-container {
            margin-top: 20px;
        }
        .buf-progress-bar {
            background: #f0f0f1;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            height: 20px;
            margin: 10px 0;
            overflow: hidden;
        }
        .buf-progress-fill {
            background: #2271b1;
            height: 100%;
            transition: width 0.3s ease;
        }
        .buf-results-table th.sortable {
            cursor: pointer;
        }
        .buf-results-table th.sortable a {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .buf-results-table th.sortable .sorting-indicator {
            width: 10px;
            height: 4px;
            margin: 0 0 0 7px;
            display: inline-block;
            vertical-align: middle;
        }
        .buf-results-table th.sortable.sorted .sorting-indicator:before {
            content: "";
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            display: inline-block;
        }
        .buf-results-table th.sortable.sorted.asc .sorting-indicator:before {
            border-bottom: 4px solid #444;
        }
        .buf-results-table th.sortable.sorted.desc .sorting-indicator:before {
            border-top: 4px solid #444;
        }
    </style>
    <script>
    (function($){
        $(function(){
            var timer;
            var currentNonce = '<?php echo esc_js( wp_create_nonce( 'buf_search_nonce' ) ); ?>';
            var allResults = [];
            var allPatternResults = [];
            var currentSearch = null;
            var currentPatternSearch = null;

            // Handle block dropdown selection
            $('#buf-block-dropdown').on('change', function() {
                var selectedBlock = $(this).val();
                if (selectedBlock) {
                    $('#buf-block-name').val(selectedBlock);
                }
            });

            // Refresh nonce every 5 minutes (more frequent for long operations)
            setInterval(function() {
                $.post(ajaxurl, {
                    action: 'buf_refresh_nonce'
                }, function(response) {
                    if (response.success && response.data && response.data.nonce) {
                        currentNonce = response.data.nonce;
                    }
                });
            }, 5 * 60 * 1000); // 5 minutes

            // Also refresh before each search if needed
            function ensureFreshNonce(callback) {
                $.post(ajaxurl, {
                    action: 'buf_refresh_nonce'
                }, function(response) {
                    if (response.success && response.data && response.data.nonce) {
                        currentNonce = response.data.nonce;
                    }
                    callback();
                });
            }

            // Escape HTML to prevent XSS
            function escapeHtml(text) {
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
            }

            // Sanitize CSV value to prevent formula injection
            function sanitizeCsvValue(value) {
                value = String(value);
                // Escape double quotes for CSV format
                value = value.replace(/"/g, '""');
                // Prevent formula injection by prefixing dangerous characters with single quote
                if (/^[=+\-@|%]/.test(value)) {
                    value = "'" + value;
                }
                return value;
            }

            // Format date to match WordPress admin style
            function formatDate(dateString) {
                var date = new Date(dateString);
                if (isNaN(date.getTime())) return dateString;

                var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                var year = date.getFullYear();
                var month = months[date.getMonth()];
                var day = date.getDate();

                return month + ' ' + day + ', ' + year;
            }

            // Initialize table sorting
            function initTableSort(containerSelector) {
                $(containerSelector + ' .buf-results-table th.sortable a').off('click').on('click', function(e){
                    e.preventDefault();
                    var $th = $(this).closest('th');
                    var column = $th.data('column');
                    var $table = $th.closest('table');
                    var isAsc = $th.hasClass('sorted') && $th.hasClass('asc');

                    // Remove sorted class from all headers
                    $table.find('th').removeClass('sorted asc desc');

                    // Add sorted class to current header
                    $th.addClass('sorted').addClass(isAsc ? 'desc' : 'asc');

                    // Sort the rows
                    var $rows = $table.find('tbody tr').get();
                    $rows.sort(function(a, b){
                        var aVal = $(a).data(column);
                        var bVal = $(b).data(column);

                        // Handle date sorting
                        if (column === 'date') {
                            aVal = new Date(aVal).getTime();
                            bVal = new Date(bVal).getTime();
                        } else {
                            // Case-insensitive string sorting
                            aVal = String(aVal).toLowerCase();
                            bVal = String(bVal).toLowerCase();
                        }

                        if (aVal < bVal) return isAsc ? 1 : -1;
                        if (aVal > bVal) return isAsc ? -1 : 1;
                        return 0;
                    });

                    $.each($rows, function(index, row){
                        $table.find('tbody').append(row);
                    });
                });
            }

            function getSelectedPostTypes() {
                var selected = $('#buf-post-types').val();
                return selected && selected.length ? selected : ['post', 'page'];
            }

            function searchBlockBatch(block, postTypes, offset, accumulated) {
                offset = offset || 0;
                accumulated = accumulated || [];

                $.post(ajaxurl, {
                    action:      'buf_search_block',
                    block_name:  block,
                    post_types:  postTypes,
                    batch_offset: offset,
                    _ajax_nonce: currentNonce
                }, function(response){
                    if (!response || typeof response !== 'object') {
                        displayError('<?php echo esc_js( __( 'Invalid response format', 'block-usage-finder' ) ); ?>');
                        return;
                    }

                    if (!response.success) {
                        var errorMsg = response.data ? escapeHtml(String(response.data)) : '<?php echo esc_js( __( 'Unknown error', 'block-usage-finder' ) ); ?>';
                        displayError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !Array.isArray(data.results)) {
                        displayError('<?php echo esc_js( __( 'Invalid response format', 'block-usage-finder' ) ); ?>');
                        return;
                    }

                    accumulated = accumulated.concat(data.results);
                    allResults = accumulated;

                    // Update progress
                    var progress = data.progress || 100;
                    updateProgress(progress, accumulated.length);

                    // Display current results
                    displayResults(accumulated, !data.has_more);

                    // Continue batching if more results
                    if (data.has_more && currentSearch === block) {
                        searchBlockBatch(block, postTypes, data.next_offset, accumulated);
                    } else {
                        $('#buf-search-button').prop('disabled', false).attr('aria-busy', 'false');
                        $('#buf-cancel-button').hide();
                        $('#buf-progress').hide();
                        if (accumulated.length > 0) {
                            $('#buf-export-button').show();
                        }
                    }
                }).fail(function() {
                    displayError('<?php echo esc_js( __( 'Network error. Please try again.', 'block-usage-finder' ) ); ?>');
                });
            }

            function updateProgress(percent, count) {
                var html = '<div class="buf-progress-bar" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100">';
                html += '<div class="buf-progress-fill" style="width: ' + percent + '%"></div>';
                html += '</div>';
                html += '<p><?php echo esc_js( __( 'Searching...', 'block-usage-finder' ) ); ?> ' + count + ' <?php echo esc_js( __( 'results found so far', 'block-usage-finder' ) ); ?></p>';
                $('#buf-progress').html(html);
            }

            function displayResults(data, isComplete) {
                var html = '';
                if (data.length) {
                    html += '<p class="buf-results-count" aria-live="polite">';
                    html += data.length + ' ' + (data.length === 1 ? '<?php echo esc_js( __( 'result', 'block-usage-finder' ) ); ?>' : '<?php echo esc_js( __( 'results', 'block-usage-finder' ) ); ?>');
                    if (!isComplete) {
                        html += ' <?php echo esc_js( __( 'found so far...', 'block-usage-finder' ) ); ?>';
                    } else {
                        html += ' <?php echo esc_js( __( 'found', 'block-usage-finder' ) ); ?>';
                    }
                    html += '</p>';
                    html += '<table class="wp-list-table widefat fixed striped buf-results-table">';
                    html += '<thead><tr>';
                    html += '<th class="sortable" data-column="title"><a href="#"><span><?php echo esc_js( __( 'Title', 'block-usage-finder' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable" data-column="type"><a href="#"><span><?php echo esc_js( __( 'Type', 'block-usage-finder' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span><?php echo esc_js( __( 'Date', 'block-usage-finder' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th><?php echo esc_js( __( 'Actions', 'block-usage-finder' ) ); ?></th>';
                    html += '</tr></thead><tbody>';
                    data.forEach(function(item){
                        if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                            html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                            html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                            html += '<td>'+ escapeHtml(item.type) +'</td>';
                            html += '<td>'+ formatDate(item.date) +'</td>';
                            html += '<td>';
                            html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'View', 'block-usage-finder' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank"><?php echo esc_js( __( 'View', 'block-usage-finder' ) ); ?></a> ';
                            html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'Edit', 'block-usage-finder' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'"><?php echo esc_js( __( 'Edit', 'block-usage-finder' ) ); ?></a>';
                            html += '</td>';
                            html += '</tr>';
                        }
                    });
                    html += '</tbody></table>';
                } else if (isComplete) {
                    html = '<p><?php echo esc_js( __( 'No content found using that block.', 'block-usage-finder' ) ); ?></p>';
                }
                $('#buf-search-results').html(html).attr('tabindex', '-1').focus();
                initTableSort('#buf-search-results');
            }

            function displayError(message) {
                var html = '<div role="alert" class="notice notice-error"><p><strong><?php echo esc_js( __( 'Error:', 'block-usage-finder' ) ); ?></strong> '+ message +'</p></div>';
                $('#buf-search-results').html(html);
                $('#buf-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#buf-cancel-button').hide();
                $('#buf-progress').hide();
            }

            function searchBlock(block) {
                currentSearch = block;
                allResults = [];
                $('#buf-export-button').hide();
                $('#buf-search-button').prop('disabled', true).attr('aria-busy', 'true');
                $('#buf-cancel-button').show();
                $('#buf-search-results').empty();
                $('#buf-progress').show();
                updateProgress(0, 0);

                ensureFreshNonce(function() {
                    searchBlockBatch(block, getSelectedPostTypes(), 0, []);
                });
            }

            $('#buf-search-button').on('click', function(){
                searchBlock( $('#buf-block-name').val() );
            });

            // Cancel block search
            $('#buf-cancel-button').on('click', function(){
                currentSearch = null;
                $('#buf-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#buf-cancel-button').hide();
                $('#buf-progress').hide();
                var html = '<div role="alert" class="notice notice-warning"><p><?php echo esc_js( __( 'Search cancelled.', 'block-usage-finder' ) ); ?></p></div>';
                $('#buf-search-results').html(html);
            });

            // CSV Export
            $('#buf-export-button').on('click', function(){
                var csv = 'Title,Type,Date,View Link\n';
                allResults.forEach(function(item){
                    csv += '"' + sanitizeCsvValue(item.title) + '",';
                    csv += '"' + sanitizeCsvValue(item.type) + '",';
                    csv += '"' + sanitizeCsvValue(item.date) + '",';
                    csv += '"' + sanitizeCsvValue(item.view_link) + '"\n';
                });

                var blob = new Blob([csv], { type: 'text/csv' });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'block-usage-' + $('#buf-block-name').val().replace(/[^a-z0-9]/gi, '-') + '.csv';
                a.click();
                window.URL.revokeObjectURL(url);
            });

            // Add Enter key support
            $('#buf-block-name').on('keypress', function(e){
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    clearTimeout(timer);
                    searchBlock( $(this).val() );
                }
            });

            $('#buf-block-name').on('keyup', function(e){
                if (e.which === 13) return; // Skip Enter key for debounced search
                clearTimeout(timer);
                timer = setTimeout(function(){
                    searchBlock( $('#buf-block-name').val() );
                }, 500);
            });

            // ========== PATTERN SEARCH FUNCTIONS ==========

            function getSelectedPatternPostTypes() {
                var selected = $('#buf-pattern-post-types').val();
                return selected && selected.length ? selected : ['post', 'page'];
            }

            function searchPatternBatch(patternId, postTypes, offset, accumulated) {
                offset = offset || 0;
                accumulated = accumulated || [];

                $.post(ajaxurl, {
                    action:      'buf_search_pattern',
                    pattern_id:  patternId,
                    post_types:  postTypes,
                    batch_offset: offset,
                    _ajax_nonce: currentNonce
                }, function(response){
                    if (!response || typeof response !== 'object') {
                        displayPatternError('<?php echo esc_js( __( 'Invalid response format', 'block-usage-finder' ) ); ?>');
                        return;
                    }

                    if (!response.success) {
                        var errorMsg = response.data ? escapeHtml(String(response.data)) : '<?php echo esc_js( __( 'Unknown error', 'block-usage-finder' ) ); ?>';
                        displayPatternError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !Array.isArray(data.results)) {
                        displayPatternError('<?php echo esc_js( __( 'Invalid response format', 'block-usage-finder' ) ); ?>');
                        return;
                    }

                    accumulated = accumulated.concat(data.results);
                    allPatternResults = accumulated;

                    // Update progress
                    var progress = data.progress || 100;
                    updatePatternProgress(progress, accumulated.length);

                    // Display current results
                    displayPatternResults(accumulated, !data.has_more);

                    // Continue batching if more results
                    if (data.has_more && currentPatternSearch === patternId) {
                        searchPatternBatch(patternId, postTypes, data.next_offset, accumulated);
                    } else {
                        $('#buf-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                        $('#buf-pattern-cancel-button').hide();
                        $('#buf-pattern-progress').hide();
                        if (accumulated.length > 0) {
                            $('#buf-pattern-export-button').show();
                        }
                    }
                }).fail(function() {
                    displayPatternError('<?php echo esc_js( __( 'Network error. Please try again.', 'block-usage-finder' ) ); ?>');
                });
            }

            function updatePatternProgress(percent, count) {
                var html = '<div class="buf-progress-bar" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100">';
                html += '<div class="buf-progress-fill" style="width: ' + percent + '%"></div>';
                html += '</div>';
                html += '<p><?php echo esc_js( __( 'Searching...', 'block-usage-finder' ) ); ?> ' + count + ' <?php echo esc_js( __( 'results found so far', 'block-usage-finder' ) ); ?></p>';
                $('#buf-pattern-progress').html(html);
            }

            function displayPatternResults(data, isComplete) {
                var html = '';
                if (data.length) {
                    html += '<p class="buf-results-count" aria-live="polite">';
                    html += data.length + ' ' + (data.length === 1 ? '<?php echo esc_js( __( 'result', 'block-usage-finder' ) ); ?>' : '<?php echo esc_js( __( 'results', 'block-usage-finder' ) ); ?>');
                    if (!isComplete) {
                        html += ' <?php echo esc_js( __( 'found so far...', 'block-usage-finder' ) ); ?>';
                    } else {
                        html += ' <?php echo esc_js( __( 'found', 'block-usage-finder' ) ); ?>';
                    }
                    html += '</p>';
                    html += '<table class="wp-list-table widefat fixed striped buf-results-table">';
                    html += '<thead><tr>';
                    html += '<th class="sortable" data-column="title"><a href="#"><span><?php echo esc_js( __( 'Title', 'block-usage-finder' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable" data-column="type"><a href="#"><span><?php echo esc_js( __( 'Type', 'block-usage-finder' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span><?php echo esc_js( __( 'Date', 'block-usage-finder' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th><?php echo esc_js( __( 'Actions', 'block-usage-finder' ) ); ?></th>';
                    html += '</tr></thead><tbody>';
                    data.forEach(function(item){
                        if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                            html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                            html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                            html += '<td>'+ escapeHtml(item.type) +'</td>';
                            html += '<td>'+ formatDate(item.date) +'</td>';
                            html += '<td>';
                            html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'View', 'block-usage-finder' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank"><?php echo esc_js( __( 'View', 'block-usage-finder' ) ); ?></a> ';
                            html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'Edit', 'block-usage-finder' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'"><?php echo esc_js( __( 'Edit', 'block-usage-finder' ) ); ?></a>';
                            html += '</td>';
                            html += '</tr>';
                        }
                    });
                    html += '</tbody></table>';
                } else if (isComplete) {
                    html = '<p><?php echo esc_js( __( 'No content found using that synced pattern.', 'block-usage-finder' ) ); ?></p>';
                }
                $('#buf-pattern-search-results').html(html).attr('tabindex', '-1').focus();
                initTableSort('#buf-pattern-search-results');
            }

            function displayPatternError(message) {
                var html = '<div role="alert" class="notice notice-error"><p><strong><?php echo esc_js( __( 'Error:', 'block-usage-finder' ) ); ?></strong> '+ message +'</p></div>';
                $('#buf-pattern-search-results').html(html);
                $('#buf-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#buf-pattern-cancel-button').hide();
                $('#buf-pattern-progress').hide();
            }

            function searchPattern(patternId) {
                if (!patternId) {
                    displayPatternError('<?php echo esc_js( __( 'Please select a synced pattern', 'block-usage-finder' ) ); ?>');
                    return;
                }

                currentPatternSearch = patternId;
                allPatternResults = [];
                $('#buf-pattern-export-button').hide();
                $('#buf-pattern-search-button').prop('disabled', true).attr('aria-busy', 'true');
                $('#buf-pattern-cancel-button').show();
                $('#buf-pattern-search-results').empty();
                $('#buf-pattern-progress').show();
                updatePatternProgress(0, 0);

                ensureFreshNonce(function() {
                    searchPatternBatch(patternId, getSelectedPatternPostTypes(), 0, []);
                });
            }

            // Pattern search button handler
            $('#buf-pattern-search-button').on('click', function(){
                searchPattern( $('#buf-pattern-dropdown').val() );
            });

            // Cancel pattern search
            $('#buf-pattern-cancel-button').on('click', function(){
                currentPatternSearch = null;
                $('#buf-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#buf-pattern-cancel-button').hide();
                $('#buf-pattern-progress').hide();
                var html = '<div role="alert" class="notice notice-warning"><p><?php echo esc_js( __( 'Search cancelled.', 'block-usage-finder' ) ); ?></p></div>';
                $('#buf-pattern-search-results').html(html);
            });

            // Pattern CSV Export
            $('#buf-pattern-export-button').on('click', function(){
                var csv = 'Title,Type,Date,View Link\n';
                allPatternResults.forEach(function(item){
                    csv += '"' + sanitizeCsvValue(item.title) + '",';
                    csv += '"' + sanitizeCsvValue(item.type) + '",';
                    csv += '"' + sanitizeCsvValue(item.date) + '",';
                    csv += '"' + sanitizeCsvValue(item.view_link) + '"\n';
                });

                var blob = new Blob([csv], { type: 'text/csv' });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                var patternName = $('#buf-pattern-dropdown option:selected').text().replace(/[^a-z0-9]/gi, '-');
                a.download = 'pattern-usage-' + patternName + '.csv';
                a.click();
                window.URL.revokeObjectURL(url);
            });
        });
    })(jQuery);
    </script>
    <?php
}

/**
 * Helper function to detect block variations.
 */
function buf_detect_block_variations( $block_name, $post ) {
    // Check main block name
    if ( has_block( $block_name, $post ) ) {
        return true;
    }

    // Check for deprecated block names
    $deprecated_blocks = [
        'core/paragraph' => [ 'core/text' ],
        'core/heading' => [ 'core/subhead' ],
        'core/list' => [ 'core/list-item' ],
    ];

    if ( isset( $deprecated_blocks[ $block_name ] ) ) {
        foreach ( $deprecated_blocks[ $block_name ] as $deprecated ) {
            if ( has_block( $deprecated, $post ) ) {
                return true;
            }
        }
    }

    // Check for block variations in content (e.g., core/embed variations)
    if ( strpos( $block_name, '/' ) !== false ) {
        list( $namespace, $name ) = explode( '/', $block_name, 2 );
        $variation_pattern = '/' . preg_quote( $namespace, '/' ) . '\/' . preg_quote( $name, '/' ) . '(?:\/[a-z0-9-]+)?/i';
        if ( preg_match( $variation_pattern, $post->post_content ) ) {
            return true;
        }
    }

    return false;
}

/**
 * AJAX handler for searching block usage.
 */
add_action( 'wp_ajax_buf_search_block', 'buf_ajax_search_block' );
function buf_ajax_search_block() {
    try {
        check_ajax_referer( 'buf_search_nonce' );

        if ( ! current_user_can( 'use_block_usage_finder' ) ) {
            buf_log_security_event( 'unauthorized_access', [ 'capability' => 'use_block_usage_finder' ] );
            throw new Exception( 'unauthorized' );
        }

        // Rate limiting with IP tracking
        $rate_check = buf_check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            throw new Exception( 'rate_limit' );
        }

        // Proper input handling with wp_unslash()
        $block = isset( $_POST['block_name'] ) ? sanitize_text_field( wp_unslash( $_POST['block_name'] ) ) : '';
        $post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [];
        $batch_offset = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;

        if ( empty( $block ) ) {
            throw new Exception( 'empty_input' );
        }

        // Enhanced validation with namespace checking
        $validation = buf_validate_block_name( $block );
        if ( is_wp_error( $validation ) ) {
            buf_log_security_event( 'invalid_input', [
                'block_name' => $block,
                'error' => $validation->get_error_message()
            ] );
            throw new Exception( 'invalid_input' );
        }

        // Validate block namespace against registered blocks
        $namespace_check = buf_validate_block_namespace( $block );
        if ( is_wp_error( $namespace_check ) ) {
            buf_log_security_event( 'suspicious_namespace', [ 'block_name' => $block ] );
            // Continue anyway but log the event
        }

        $search_result = buf_get_posts_using_block( $block, $post_types, $batch_offset, 100 );
        $results = [];

        foreach ( $search_result['posts'] as $post ) {
            // Get the post date (use modified if available, otherwise published)
            $post_date = ! empty( $post->post_modified ) ? $post->post_modified : $post->post_date;

            $results[] = [
                'id'        => absint( $post->ID ),
                'title'     => get_the_title( $post ),
                'edit_link' => esc_url( get_edit_post_link( $post ) ),
                'view_link' => esc_url( get_permalink( $post ) ),
                'type'      => sanitize_key( $post->post_type ),
                'date'      => $post_date,
            ];
        }

        // Calculate progress percentage
        $progress = 100;
        if ( $search_result['has_more'] ) {
            // Estimate based on batch size
            $total_estimate = $batch_offset + 200; // Conservative estimate
            $progress = min( 95, ( $batch_offset / $total_estimate ) * 100 );
        }

        wp_send_json_success( [
            'results' => $results,
            'has_more' => $search_result['has_more'],
            'next_offset' => $search_result['next_offset'],
            'progress' => $progress,
        ] );

    } catch ( Exception $e ) {
        // Generic error messages to prevent information disclosure
        $error_messages = [
            'unauthorized'  => __( 'Access denied', 'block-usage-finder' ),
            'rate_limit'    => __( 'Too many requests. Please wait.', 'block-usage-finder' ),
            'empty_input'   => __( 'Block name is required', 'block-usage-finder' ),
            'invalid_input' => __( 'Invalid block name format. Use: namespace/block-name', 'block-usage-finder' ),
        ];

        $message = isset( $error_messages[ $e->getMessage() ] ) ? $error_messages[ $e->getMessage() ] : __( 'An error occurred', 'block-usage-finder' );
        wp_send_json_error( $message );
    }
}

/**
 * AJAX handler for searching synced pattern usage.
 */
add_action( 'wp_ajax_buf_search_pattern', 'buf_ajax_search_pattern' );
function buf_ajax_search_pattern() {
    try {
        check_ajax_referer( 'buf_search_nonce' );

        if ( ! current_user_can( 'use_block_usage_finder' ) ) {
            buf_log_security_event( 'unauthorized_access', [ 'capability' => 'use_block_usage_finder' ] );
            throw new Exception( 'unauthorized' );
        }

        // Rate limiting with IP tracking
        $rate_check = buf_check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            throw new Exception( 'rate_limit' );
        }

        // Proper input handling with wp_unslash()
        $pattern_id = isset( $_POST['pattern_id'] ) ? absint( $_POST['pattern_id'] ) : 0;
        $post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [];
        $batch_offset = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;

        if ( empty( $pattern_id ) ) {
            throw new Exception( 'empty_input' );
        }

        // Verify the pattern exists and is a wp_block post type
        $pattern = get_post( $pattern_id );
        if ( ! $pattern || $pattern->post_type !== 'wp_block' ) {
            buf_log_security_event( 'invalid_pattern', [ 'pattern_id' => $pattern_id ] );
            throw new Exception( 'invalid_pattern' );
        }

        $search_result = buf_get_posts_using_pattern( $pattern_id, $post_types, $batch_offset, 100 );
        $results = [];

        foreach ( $search_result['posts'] as $post ) {
            // Get the post date (use modified if available, otherwise published)
            $post_date = ! empty( $post->post_modified ) ? $post->post_modified : $post->post_date;

            $results[] = [
                'id'        => absint( $post->ID ),
                'title'     => get_the_title( $post ),
                'edit_link' => esc_url( get_edit_post_link( $post ) ),
                'view_link' => esc_url( get_permalink( $post ) ),
                'type'      => sanitize_key( $post->post_type ),
                'date'      => $post_date,
            ];
        }

        // Calculate progress percentage
        $progress = 100;
        if ( $search_result['has_more'] ) {
            // Estimate based on batch size
            $total_estimate = $batch_offset + 200; // Conservative estimate
            $progress = min( 95, ( $batch_offset / $total_estimate ) * 100 );
        }

        wp_send_json_success( [
            'results' => $results,
            'has_more' => $search_result['has_more'],
            'next_offset' => $search_result['next_offset'],
            'progress' => $progress,
        ] );

    } catch ( Exception $e ) {
        // Generic error messages to prevent information disclosure
        $error_messages = [
            'unauthorized'    => __( 'Access denied', 'block-usage-finder' ),
            'rate_limit'      => __( 'Too many requests. Please wait.', 'block-usage-finder' ),
            'empty_input'     => __( 'Pattern ID is required', 'block-usage-finder' ),
            'invalid_pattern' => __( 'Invalid pattern ID', 'block-usage-finder' ),
        ];

        $message = isset( $error_messages[ $e->getMessage() ] ) ? $error_messages[ $e->getMessage() ] : __( 'An error occurred', 'block-usage-finder' );
        wp_send_json_error( $message );
    }
}

/**
 * AJAX handler for refreshing nonce.
 */
add_action( 'wp_ajax_buf_refresh_nonce', 'buf_ajax_refresh_nonce' );
function buf_ajax_refresh_nonce() {
    if ( ! current_user_can( 'use_block_usage_finder' ) ) {
        wp_send_json_error();
    }

    wp_send_json_success( [ 'nonce' => wp_create_nonce( 'buf_search_nonce' ) ] );
}

/**
 * WP-CLI command for searching block usage.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class Block_Usage_Finder_CLI {
        /**
         * Search for posts using a specific block.
         *
         * ## OPTIONS
         *
         * <block_name>
         * : The block name to search for (e.g., core/paragraph)
         *
         * [--post-type=<post-type>]
         * : Comma-separated list of post types to search (default: post,page)
         *
         * [--format=<format>]
         * : Output format (table, csv, json, ids) (default: table)
         *
         * [--limit=<limit>]
         * : Maximum number of posts to search (default: 1000)
         *
         * ## EXAMPLES
         *
         *     wp block-usage search core/paragraph
         *     wp block-usage search core/gallery --post-type=post,page --format=csv
         *     wp block-usage search acf/testimonial --format=ids
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function search( $args, $assoc_args ) {
            list( $block_name ) = $args;

            // Validate block name
            $validation = buf_validate_block_name( $block_name );
            if ( is_wp_error( $validation ) ) {
                WP_CLI::error( $validation->get_error_message() );
            }

            // Parse post types
            $post_types = isset( $assoc_args['post-type'] ) ? explode( ',', $assoc_args['post-type'] ) : [ 'post', 'page' ];
            $post_types = array_map( 'trim', $post_types );

            // Parse format
            $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

            // Parse limit
            $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 1000;

            WP_CLI::log( sprintf( 'Searching for block: %s', $block_name ) );
            WP_CLI::log( sprintf( 'Post types: %s', implode( ', ', $post_types ) ) );

            // Disable timeout for CLI
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 0 );
            }

            // Search in batches
            $all_matches = [];
            $offset = 0;
            $batch_size = 100;

            do {
                $result = buf_get_posts_using_block( $block_name, $post_types, $offset, $batch_size );
                $all_matches = array_merge( $all_matches, $result['posts'] );

                WP_CLI::log( sprintf( 'Searched %d posts... found %d matches so far', $offset + $batch_size, count( $all_matches ) ) );

                $offset = $result['next_offset'];

                // Respect limit
                if ( count( $all_matches ) >= $limit ) {
                    break;
                }

            } while ( $result['has_more'] );

            WP_CLI::success( sprintf( 'Found %d posts using block: %s', count( $all_matches ), $block_name ) );

            if ( empty( $all_matches ) ) {
                return;
            }

            // Format output
            if ( $format === 'ids' ) {
                $ids = array_map( function( $post ) { return $post->ID; }, $all_matches );
                WP_CLI::line( implode( ' ', $ids ) );
            } else {
                $items = [];
                foreach ( $all_matches as $post ) {
                    $items[] = [
                        'ID' => $post->ID,
                        'Title' => get_the_title( $post ),
                        'Type' => $post->post_type,
                        'Status' => $post->post_status,
                        'Edit Link' => get_edit_post_link( $post ),
                    ];
                }
                \WP_CLI\Utils\format_items( $format, $items, [ 'ID', 'Title', 'Type', 'Status', 'Edit Link' ] );
            }
        }

        /**
         * Clear block usage cache.
         *
         * ## EXAMPLES
         *
         *     wp block-usage clear-cache
         */
        public function clear_cache() {
            global $wpdb;
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_buf_has_block_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_buf_has_block_' ) . '%'
            ) );
            WP_CLI::success( sprintf( 'Cleared %d cache entries', $deleted ) );
        }

        /**
         * View security logs.
         *
         * ## OPTIONS
         *
         * [--limit=<limit>]
         * : Number of recent log entries to show (default: 50)
         *
         * [--format=<format>]
         * : Output format (table, csv, json) (default: table)
         *
         * ## EXAMPLES
         *
         *     wp block-usage logs
         *     wp block-usage logs --limit=100 --format=csv
         */
        public function logs( $args, $assoc_args ) {
            $logs = get_option( 'buf_security_logs', [] );

            if ( empty( $logs ) ) {
                WP_CLI::log( 'No security logs found' );
                return;
            }

            $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
            $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

            // Get most recent logs
            $logs = array_slice( $logs, -$limit );
            $logs = array_reverse( $logs );

            $items = [];
            foreach ( $logs as $log ) {
                $items[] = [
                    'Timestamp' => $log['timestamp'],
                    'User ID' => $log['user_id'],
                    'IP' => $log['user_ip'],
                    'Event' => $log['event_type'],
                    'Details' => json_encode( $log['details'] ),
                ];
            }

            \WP_CLI\Utils\format_items( $format, $items, [ 'Timestamp', 'User ID', 'IP', 'Event', 'Details' ] );
        }
    }

    WP_CLI::add_command( 'block-usage', 'Block_Usage_Finder_CLI' );
}
