<?php
/**
 * Plugin Name: Find Blocks, Patterns & Shortcodes
 * Description: A powerful finder tool to audit your site. Locate instances of any Block, Pattern, or Shortcode and export the full usage report to CSV.
 * Version:     1.1.4
 * Author:      Matthew Cowan
 * Author URI:  https://mnc4.com
 * Text Domain: find-blocks-patterns-shortcodes
 * License:     GPL2
 * Requires at least: 5.0
 * Requires PHP: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version constant
define( 'FBPS_VERSION', '1.1.4' );
define( 'FBPS_BATCH_SIZE', 100 ); // posts scanned per AJAX batch

/**
 * Plugin activation - register custom capability.
 */
register_activation_hook( __FILE__, 'fbps_activate' );
function fbps_activate( $network_wide ) {
    if ( is_multisite() && $network_wide ) {
        // Network activation - activate for all sites
        $sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            fbps_activate_single_site();
            restore_current_blog();
        }
    } else {
        fbps_activate_single_site();
    }
}

/**
 * Activate plugin for a single site.
 */
function fbps_activate_single_site() {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( 'use_find_blocks_patterns_shortcodes' );
    }

    // Optionally add to editor role
    $editor = get_role( 'editor' );
    if ( $editor && apply_filters( 'fbps_allow_editor_access', false ) ) {
        $editor->add_cap( 'use_find_blocks_patterns_shortcodes' );
    }
}

/**
 * Activate plugin for new sites in multisite.
 */
add_action( 'wp_initialize_site', 'fbps_new_site_activation', 10, 1 );
function fbps_new_site_activation( $new_site ) {
    if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
        switch_to_blog( $new_site->blog_id );
        fbps_activate_single_site();
        restore_current_blog();
    }
}

/**
 * Plugin deactivation - remove custom capability.
 */
register_deactivation_hook( __FILE__, 'fbps_deactivate' );
function fbps_deactivate() {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->remove_cap( 'use_find_blocks_patterns_shortcodes' );
    }

    $editor = get_role( 'editor' );
    if ( $editor ) {
        $editor->remove_cap( 'use_find_blocks_patterns_shortcodes' );
    }

    // Clean up transients
    global $wpdb;
    $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_fbps_rate_limit_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_fbps_rate_limit_' ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_fbps_has_block_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_fbps_has_block_' ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_fbps_has_pattern_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_fbps_has_pattern_' ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_fbps_has_shortcode_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_fbps_has_shortcode_' ) . '%'
    ) );
    delete_transient( 'fbps_all_shortcodes' );
}

/**
 * Enqueue admin assets (CSS and JavaScript).
 */
add_action( 'admin_enqueue_scripts', 'fbps_enqueue_admin_assets' );
function fbps_enqueue_admin_assets( $hook ) {
	// Only load on our plugin's admin page
	if ( 'tools_page_find-blocks-patterns-shortcodes' !== $hook ) {
		return;
	}

	// Enqueue CSS
	wp_enqueue_style(
		'fbps-admin-css',
		plugins_url( 'assets/css/admin.css', __FILE__ ),
		[],
		FBPS_VERSION
	);

	// Enqueue JavaScript (depends on jQuery)
	wp_enqueue_script(
		'fbps-admin-js',
		plugins_url( 'assets/js/admin.js', __FILE__ ),
		[ 'jquery' ],
		FBPS_VERSION,
		true
	);

	// Localize script with data and translations
	wp_localize_script(
		'fbps-admin-js',
		'fbpsData',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'fbps_search_nonce' ),
			'i18n'    => [
				'searching'             => __( 'Searching', 'find-blocks-patterns-shortcodes' ),
				/* translators: %d: number of results found */
				'searchingProgress'     => __( 'Searching... %d results found so far', 'find-blocks-patterns-shortcodes' ),
				'result'                => __( 'result', 'find-blocks-patterns-shortcodes' ),
				'results'               => __( 'results', 'find-blocks-patterns-shortcodes' ),
				'foundSoFar'            => __( 'found so far...', 'find-blocks-patterns-shortcodes' ),
				'found'                 => __( 'found', 'find-blocks-patterns-shortcodes' ),
				'title'                 => __( 'Title', 'find-blocks-patterns-shortcodes' ),
				'noTitle'               => __( '(no title)', 'find-blocks-patterns-shortcodes' ),
				'viewLink'              => __( 'View Link', 'find-blocks-patterns-shortcodes' ),
				'type'                  => __( 'Type', 'find-blocks-patterns-shortcodes' ),
				'date'                  => __( 'Modified', 'find-blocks-patterns-shortcodes' ),
				'actions'               => __( 'Actions', 'find-blocks-patterns-shortcodes' ),
				'view'                  => __( 'View', 'find-blocks-patterns-shortcodes' ),
				'edit'                  => __( 'Edit', 'find-blocks-patterns-shortcodes' ),
				'error'                 => __( 'Error:', 'find-blocks-patterns-shortcodes' ),
				'invalidResponseFormat' => __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ),
				'unknownError'          => __( 'Unknown error', 'find-blocks-patterns-shortcodes' ),
				'networkError'          => __( 'Network error. Please try again.', 'find-blocks-patterns-shortcodes' ),
				'searchCancelled'       => __( 'Search cancelled.', 'find-blocks-patterns-shortcodes' ),
				'partialResults'        => __( 'The search stopped early. These are the results found before it stopped.', 'find-blocks-patterns-shortcodes' ),
				/* translators: 1: number of posts scanned, 2: total number of posts */
				'truncatedNotice'       => __( 'Searched the first %1$s of %2$s posts. To search more, raise the fbps_query_limit filter (up to 1000).', 'find-blocks-patterns-shortcodes' ),
				'noBlockResults'        => __( 'No content found using that block.', 'find-blocks-patterns-shortcodes' ),
				'noPatternResults'      => __( 'No content found using that synced pattern.', 'find-blocks-patterns-shortcodes' ),
				'noShortcodeResults'    => __( 'No content found using that shortcode.', 'find-blocks-patterns-shortcodes' ),
				'selectPattern'           => __( 'Please select a synced pattern', 'find-blocks-patterns-shortcodes' ),
				'selectShortcode'         => __( 'Please select a shortcode', 'find-blocks-patterns-shortcodes' ),
				'classOrAnchorRequired'   => __( 'Enter a block name, CSS class, or HTML anchor', 'find-blocks-patterns-shortcodes' ),
				'noAttributeResults'      => __( 'No content found matching that class or anchor.', 'find-blocks-patterns-shortcodes' ),
				'cssClass'                => __( 'CSS Class', 'find-blocks-patterns-shortcodes' ),
				'htmlAnchor'              => __( 'HTML Anchor', 'find-blocks-patterns-shortcodes' ),
			],
		]
	);
}

/**
 * Add plugin action links (Settings link on plugins page).
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'fbps_plugin_action_links' );
function fbps_plugin_action_links( $links ) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'tools.php?page=find-blocks-patterns-shortcodes' ) ),
        __( 'Find Content', 'find-blocks-patterns-shortcodes' )
    );
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Clean up user-specific transients on user deletion.
 */
add_action( 'delete_user', 'fbps_cleanup_user_transients' );
function fbps_cleanup_user_transients( $user_id ) {
    global $wpdb;
    $user_id = absint( $user_id );
    $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_fbps_rate_limit_user_' . $user_id ) . '%',
        $wpdb->esc_like( '_transient_timeout_fbps_rate_limit_user_' . $user_id ) . '%'
    ) );
}

/**
 * Get client IP address securely.
 */
function fbps_get_client_ip() {
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
function fbps_log_security_event( $event_type, $details = [] ) {
    if ( ! apply_filters( 'fbps_enable_security_logging', true ) ) {
        return;
    }

    $log_entry = [
        'timestamp'  => current_time( 'mysql' ),
        'user_id'    => get_current_user_id(),
        'user_ip'    => fbps_get_client_ip(),
        'event_type' => sanitize_key( $event_type ),
        'details'    => $details,
        'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
    ];

    // Store in options table
    $logs = get_option( 'fbps_security_logs', [] );
    if ( ! is_array( $logs ) ) {
        $logs = [];
    }
    $logs[] = $log_entry;

    // Keep only last 1000 entries
    if ( count( $logs ) > 1000 ) {
        $logs = array_slice( $logs, -1000 );
    }

    update_option( 'fbps_security_logs', $logs, false );

    // Trigger action for external logging systems
    do_action( 'fbps_security_event', $log_entry );
}

/**
 * Validate block name format and security.
 */
function fbps_validate_block_name( $block_name ) {
    // Length validation
    if ( strlen( $block_name ) > 100 ) {
        return new WP_Error( 'invalid_length', __( 'Block name too long', 'find-blocks-patterns-shortcodes' ) );
    }

    // Format validation (namespace/block-name)
    if ( ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/i', $block_name ) ) {
        return new WP_Error( 'invalid_format', __( 'Invalid block name format. Use: namespace/block-name', 'find-blocks-patterns-shortcodes' ) );
    }

    // Prevent path traversal attempts
    if ( strpos( $block_name, '..' ) !== false ) {
        return new WP_Error( 'invalid_characters', __( 'Invalid characters detected', 'find-blocks-patterns-shortcodes' ) );
    }

    // Blacklist dangerous patterns
    $blacklist = [ 'script', 'eval', 'javascript:', 'data:', 'vbscript:', 'onload', 'onerror' ];
    foreach ( $blacklist as $pattern ) {
        if ( stripos( $block_name, $pattern ) !== false ) {
            return new WP_Error( 'dangerous_pattern', __( 'Invalid block name', 'find-blocks-patterns-shortcodes' ) );
        }
    }

    return true;
}

/**
 * Validate a CSS class name or HTML anchor for attribute search.
 *
 * @param string $value The class name or anchor to validate.
 * @return true|WP_Error True if valid, WP_Error otherwise.
 */
function fbps_validate_attribute_search( $value ) {
    if ( strlen( $value ) > 100 ) {
        return new WP_Error( 'invalid_length', __( 'Value too long', 'find-blocks-patterns-shortcodes' ) );
    }

    // Allow alphanumeric, hyphens, underscores (valid CSS class / HTML ID characters)
    if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $value ) ) {
        return new WP_Error( 'invalid_format', __( 'Invalid format. Use only letters, numbers, hyphens, and underscores.', 'find-blocks-patterns-shortcodes' ) );
    }

    // Blacklist dangerous patterns
    $blacklist = [ 'script', 'eval', 'javascript', 'vbscript', 'onload', 'onerror' ];
    foreach ( $blacklist as $pattern ) {
        if ( stripos( $value, $pattern ) !== false ) {
            return new WP_Error( 'dangerous_pattern', __( 'Invalid value', 'find-blocks-patterns-shortcodes' ) );
        }
    }

    return true;
}

/**
 * Validate block namespace against known/registered blocks.
 */
function fbps_validate_block_namespace( $block_name ) {
    // Common/known namespaces
    $known_namespaces = [ 'core', 'acf', 'yoast', 'jetpack', 'woocommerce', 'gravityforms', 'elementor' ];

    // Get namespace from block name
    if ( strpos( $block_name, '/' ) !== false ) {
        list( $namespace, $name ) = explode( '/', $block_name, 2 );

        // Check if namespace is known
        if ( ! in_array( strtolower( $namespace ), $known_namespaces, true ) ) {
            // Check if block is registered in WordPress
            if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
                return new WP_Error( 'unknown_namespace', sprintf(
                    /* translators: %s: Block namespace name */
                    __( 'Block namespace "%s" is not recognized. This may be a custom block.', 'find-blocks-patterns-shortcodes' ),
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
/**
 * Rate limit search requests per user and per IP.
 *
 * One counter per identity, incremented by every request, in a fixed
 * calendar-minute bucket. That is all. A search of up to 1000 posts is up to
 * ten requests, so the ceilings are set so that a user re-running full
 * searches back to back cannot reach them (about 30 requests a minute is the
 * realistic maximum), while a client hammering the endpoint is cut off well
 * before it can cost the server much: each request is bounded to one batch
 * of at most FBPS_BATCH_SIZE posts by the query limit in the search helpers.
 *
 * Trade-off, accepted on purpose: because every batch counts, a very heavy
 * legitimate burst could hit the ceiling part-way through a search. The UI
 * keeps the results already found on screen and exportable, and says the
 * search stopped early; the client can retry a minute later.
 *
 * Deliberately no "searches versus batches" distinction and no continuation
 * state. An earlier version tried to count searches by trusting or validating
 * the client's batch_offset, and every refinement of that opened another way
 * to be classified as a continuation. Counting every request leaves nothing
 * to classify.
 *
 * The bucket key includes the current UTC minute, so the window rolls over
 * on its own and a refused retry does not push the unlock time back. Both
 * counters are checked before either is incremented, so a request refused by
 * one is not charged to the other.
 *
 * @return true|WP_Error
 */
function fbps_check_rate_limit() {
    $uid = absint( get_current_user_id() );
    $iph = md5( fbps_get_client_ip() );
    $window = gmdate( 'YmdHi' );

    $limits = [
        [ 'key' => 'user_' . $uid, 'max' => 120 ], // requests / min / user (12 full ten-batch searches)
        [ 'key' => 'ip_' . $iph,   'max' => 300 ], // requests / min / IP (a shared address with several editors)
    ];

    foreach ( $limits as &$limit ) {
        $limit['full_key'] = 'fbps_rate_limit_' . sanitize_key( $limit['key'] . '_' . $window );
        // Ensure we're working with integers only (object injection prevention)
        $limit['current'] = absint( get_transient( $limit['full_key'] ) );

        if ( $limit['current'] >= $limit['max'] ) {
            fbps_log_security_event( 'rate_limit_exceeded', [
                'key' => $limit['key'],
                'requests' => $limit['current'],
                'limit' => $limit['max']
            ] );
            return new WP_Error( 'rate_limit', __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ) );
        }
    }
    unset( $limit );

    foreach ( $limits as $limit ) {
        // Two minutes, not one: a bucket opened at :59 must outlive the minute
        // it belongs to, and expiry resets are harmless because the key rotates.
        set_transient( $limit['full_key'], $limit['current'] + 1, 2 * MINUTE_IN_SECONDS );
    }

    return true;
}

/**
 * The most posts one search may scan.
 *
 * Read from the fbps_query_limit filter (default 500, hard cap 1000). A
 * filter that returns 0, '', or something non-numeric must not switch every
 * search off: absint() turns all of those into 0, and a limit of 0 makes the
 * very first batch a terminal empty one - reported to the user as "no
 * results". Anything below 1 falls back to the default.
 *
 * @return int
 */
function fbps_get_query_limit() {
    // (int), not absint(): a negative value is a mistake and must fall back to
    // the default, not become its absolute value.
    $limit = (int) apply_filters( 'fbps_query_limit', 500 );
    if ( $limit < 1 ) {
        $limit = 500;
    }
    return min( $limit, 1000 );
}

/**
 * Get all synced patterns (reusable blocks).
 */
function fbps_get_synced_patterns() {
    // Cache the synced patterns for 5 minutes to reduce meta_query overhead
    $cache_key = 'fbps_synced_patterns';
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
    ]);

    // Filter out unsynced patterns.
    $patterns = array_filter($patterns, function($pattern) {
        $sync_status = get_post_meta($pattern->ID, 'wp_pattern_sync_status', true);
        return empty($sync_status) || $sync_status !== 'unsynced';
    });

    set_transient( $cache_key, $patterns, 5 * MINUTE_IN_SECONDS );
    return $patterns;
}

/**
 * Walk parsed blocks (and their inner blocks) looking for a core/block
 * reference to $pattern_id.
 *
 * @param array $blocks     Output of parse_blocks().
 * @param int   $pattern_id The wp_block post ID to look for.
 * @return bool
 */
function fbps_blocks_reference_pattern( $blocks, $pattern_id ) {
    foreach ( $blocks as $block ) {
        // Match exactly what WordPress renders. Core hands attrs.ref to
        // get_post(), which accepts an int, a numeric string ("12") and a
        // float (12.7), and renders pattern 12 for all three - so all three
        // must match here. A non-numeric string ("12abc") is not rendered,
        // and a bare (int) cast would wrongly turn it into 12.
        if ( isset( $block['blockName'] ) && 'core/block' === $block['blockName']
            && isset( $block['attrs']['ref'] )
            && is_numeric( $block['attrs']['ref'] )
            && (int) $block['attrs']['ref'] === $pattern_id ) {
            return true;
        }

        if ( ! empty( $block['innerBlocks'] ) && fbps_blocks_reference_pattern( $block['innerBlocks'], $pattern_id ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Does this post content use the given synced pattern?
 *
 * Uses WordPress's own block parser rather than a regex over the serialized
 * markup. A regex has to model the attribute JSON, and every time the block
 * format grows the regex silently starts returning false negatives:
 *
 *   - core/block is a void block, so the delimiter ends "} /-->", not "} -->".
 *   - Pattern overrides (WP 6.6+) and renamed instances nest objects inside
 *     attrs, so any [^}]* style scan stops at the first inner closing brace.
 *   - "ref":12 must not match a reference to pattern 120.
 *
 * parse_blocks() is core's serializer in reverse, so it cannot drift from it.
 * The strpos() pre-check keeps the common case (a post that references no
 * pattern at all) as cheap as the old regex, because parse_blocks() only runs
 * on content that contains a wp:block delimiter.
 *
 * @param string $content    Raw post_content.
 * @param int    $pattern_id The wp_block post ID to look for.
 * @return bool
 */
function fbps_content_uses_pattern( $content, $pattern_id ) {
    if ( ! is_string( $content ) || '' === $content ) {
        return false;
    }

    $pattern_id = absint( $pattern_id );
    if ( ! $pattern_id ) {
        return false;
    }

    // Cheap reject: no synced pattern reference can exist without this substring.
    if ( false === strpos( $content, 'wp:block' ) ) {
        return false;
    }

    return fbps_blocks_reference_pattern( parse_blocks( $content ), $pattern_id );
}

/**
 * Drop the cached shortcode list when the set of registered shortcodes can
 * change: a plugin activating or deactivating, or a theme switch. Same gap
 * as the pattern cache below - without this a shortcode from a newly
 * activated plugin took up to five minutes to appear in the dropdown.
 */
add_action( 'activated_plugin', 'fbps_flush_shortcode_cache' );
add_action( 'deactivated_plugin', 'fbps_flush_shortcode_cache' );
add_action( 'switch_theme', 'fbps_flush_shortcode_cache' );
function fbps_flush_shortcode_cache() {
    delete_transient( 'fbps_all_shortcodes' );
}

/**
 * Drop the cached synced-pattern list whenever a wp_block post changes.
 *
 * fbps_get_synced_patterns() caches the dropdown for five minutes. Without
 * this, a pattern created just before opening the tool does not appear until
 * the transient expires, and the only remedy is the CLI clear-cache command.
 *
 * save_post_{post_type} covers create, update, publish and trash (trashing is
 * a status change through wp_update_post). before_delete_post covers
 * permanent deletion. It is used rather than deleted_post because the post
 * still exists when it fires, so get_post() can read the type on every
 * WordPress version this plugin supports; deleted_post only passes the post
 * object from 5.5, and on 5.0-5.4 the row is already gone by then.
 */
add_action( 'save_post_wp_block', 'fbps_flush_pattern_cache' );
add_action( 'before_delete_post', 'fbps_flush_pattern_cache_on_delete' );
function fbps_flush_pattern_cache() {
    delete_transient( 'fbps_synced_patterns' );
}
function fbps_flush_pattern_cache_on_delete( $post_id ) {
    $post = get_post( $post_id );
    if ( $post && 'wp_block' === $post->post_type ) {
        fbps_flush_pattern_cache();
    }
}

/**
 * Returns an array of WP_Post objects that contain the specified synced pattern.
 */
function fbps_get_posts_using_pattern( $pattern_id, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = fbps_get_query_limit();

    // The limit is the most posts one search may scan. An offset at or past
    // it is a terminal batch; the last batch before it is clamped so the scan
    // never runs past the limit. Callers read 'scanned' to tell a capped
    // scan from a genuine end of content.
    $batch_offset = absint( $batch_offset );
    if ( $batch_offset >= $limit ) {
        return [ 'posts' => [], 'has_more' => false, 'next_offset' => $batch_offset, 'scanned' => 0, 'limit' => $limit ];
    }
    $batch_size = min( absint( $batch_size ), $limit - $batch_offset );

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
    $processed = 0; // IDs actually examined; the timeout below can stop the loop early


    foreach ( $ids as $post_id ) {
        // Timeout protection
        if ( microtime( true ) - $start_time > 25 ) { // 25 second safeguard
            fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

        $processed++;

        $post_id = absint( $post_id ); // Extra validation

        // Use object cache for pattern detection with 5-minute TTL
        $cache_key = 'fbps_has_pattern_' . md5( $pattern_id . '_' . $post_id );
        $has_pattern_cached = wp_cache_get( $cache_key, 'find-blocks-patterns-shortcodes' );

        if ( false === $has_pattern_cached ) {
            $post = get_post( $post_id );
            $has_pattern_cached = fbps_content_uses_pattern( $post->post_content, $pattern_id ) ? 'yes' : 'no';
            wp_cache_set( $cache_key, $has_pattern_cached, 'find-blocks-patterns-shortcodes', 300 ); // 5-minute cache
        }

        if ( 'yes' === $has_pattern_cached ) {
            $matches[] = get_post( $post_id );
        }
    }

    // If the 25-second guard stopped the loop, only $processed of the fetched
    // IDs were examined. scanned and next_offset are derived from that count so
    // the caller resumes at the first unexamined post instead of skipping the
    // rest of the batch - and a timeout on the last batch is not reported as a
    // complete scan. has_more is true after a timeout because this batch itself
    // still has posts to examine.
    $timed_out = $processed < count( $ids );

    return [
        'posts' => $matches,
        'has_more' => $timed_out ? $processed > 0 : ( count( $ids ) === $batch_size && ( $batch_offset + $batch_size ) < $limit ),
        'next_offset' => $batch_offset + $processed,
        'scanned' => $processed,
        'timed_out' => $timed_out,
        'limit' => $limit,
    ];
}

/**
 * Get all registered shortcodes.
 */
function fbps_get_all_shortcodes() {
	// Cache the shortcodes for 5 minutes to reduce overhead
	$cache_key = 'fbps_all_shortcodes';
	$cached_shortcodes = get_transient( $cache_key );

	if ( false !== $cached_shortcodes ) {
		return $cached_shortcodes;
	}

	global $shortcode_tags;
	$shortcodes = [];

	if ( ! empty( $shortcode_tags ) && is_array( $shortcode_tags ) ) {
		ksort( $shortcode_tags );
		foreach ( $shortcode_tags as $tag => $callback ) {
			$shortcodes[] = [
				'tag'      => sanitize_key( $tag ),
				'callback' => is_string( $callback ) ? $callback : 'Closure/Array',
			];
		}
	}

	set_transient( $cache_key, $shortcodes, 5 * MINUTE_IN_SECONDS );
	return $shortcodes;
}

/**
 * Validate shortcode name format and security.
 */
function fbps_validate_shortcode_name( $shortcode_name ) {
	// Length validation
	if ( strlen( $shortcode_name ) > 50 ) {
		return new WP_Error( 'invalid_length', __( 'Shortcode name too long', 'find-blocks-patterns-shortcodes' ) );
	}

	// Format validation (alphanumeric, hyphens, underscores only)
	if ( ! preg_match( '/^[a-z0-9_-]+$/i', $shortcode_name ) ) {
		return new WP_Error( 'invalid_format', __( 'Invalid shortcode name format. Use only letters, numbers, hyphens, and underscores', 'find-blocks-patterns-shortcodes' ) );
	}

	// Prevent path traversal attempts
	if ( strpos( $shortcode_name, '..' ) !== false ) {
		return new WP_Error( 'invalid_characters', __( 'Invalid characters detected', 'find-blocks-patterns-shortcodes' ) );
	}

	// Blacklist dangerous patterns
	$blacklist = [ 'script', 'eval', 'javascript:', 'data:', 'vbscript:', 'onload', 'onerror' ];
	foreach ( $blacklist as $pattern ) {
		if ( stripos( $shortcode_name, $pattern ) !== false ) {
			return new WP_Error( 'dangerous_pattern', __( 'Invalid shortcode name', 'find-blocks-patterns-shortcodes' ) );
		}
	}

	return true;
}

/**
 * Returns an array of WP_Post objects that contain the specified shortcode.
 */
function fbps_get_posts_using_shortcode( $shortcode_name, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
	$limit = fbps_get_query_limit();

	// The limit is the most posts one search may scan. An offset at or past
	// it is a terminal batch; the last batch before it is clamped so the scan
	// never runs past the limit. Callers read 'scanned' to tell a capped
	// scan from a genuine end of content.
	$batch_offset = absint( $batch_offset );
	if ( $batch_offset >= $limit ) {
		return [ 'posts' => [], 'has_more' => false, 'next_offset' => $batch_offset, 'scanned' => 0, 'limit' => $limit ];
	}
	$batch_size = min( absint( $batch_size ), $limit - $batch_offset );

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
	$processed = 0; // IDs actually examined; the timeout below can stop the loop early

	foreach ( $ids as $post_id ) {
		// Timeout protection
		if ( microtime( true ) - $start_time > 25 ) { // 25 second safeguard
			fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
			break;
		}

		$processed++;

		$post_id = absint( $post_id ); // Extra validation

		// Use object cache for shortcode detection with 5-minute TTL
		$cache_key = 'fbps_has_shortcode_' . md5( $shortcode_name . '_' . $post_id );
		$has_shortcode_cached = wp_cache_get( $cache_key, 'find-blocks-patterns-shortcodes' );

		if ( false === $has_shortcode_cached ) {
			$post = get_post( $post_id );
			// Check if post content contains the shortcode
			$has_shortcode_cached = has_shortcode( $post->post_content, $shortcode_name ) ? 'yes' : 'no';
			wp_cache_set( $cache_key, $has_shortcode_cached, 'find-blocks-patterns-shortcodes', 300 ); // 5-minute cache
		}

		if ( 'yes' === $has_shortcode_cached ) {
			$matches[] = get_post( $post_id );
		}
	}

	// If the 25-second guard stopped the loop, only $processed of the fetched
	// IDs were examined. scanned and next_offset are derived from that count so
	// the caller resumes at the first unexamined post instead of skipping the
	// rest of the batch - and a timeout on the last batch is not reported as a
	// complete scan. has_more is true after a timeout because this batch itself
	// still has posts to examine.
	$timed_out = $processed < count( $ids );

	return [
		'posts' => $matches,
		'has_more' => $timed_out ? $processed > 0 : ( count( $ids ) === $batch_size && ( $batch_offset + $batch_size ) < $limit ),
		'next_offset' => $batch_offset + $processed,
		'scanned' => $processed,
		'timed_out' => $timed_out,
		'limit' => $limit,
	];
}

/**
 * Get total count of posts to search through for progress calculation.
 */
function fbps_get_total_posts_count( $post_types = [] ) {
    // Default to all public post types if none specified
    if ( empty( $post_types ) ) {
        $post_types = [ 'post', 'page' ];
    } else {
        // Sanitize post types
        $post_types = array_map( 'sanitize_key', (array) $post_types );
    }

    // Count only the statuses the searches actually scan. The search helpers
    // query with post_status => 'any', which excludes statuses flagged
    // exclude_from_search (auto-draft, trash). Summing every status would
    // overstate the total and could report a scan as truncated when it had
    // in fact reached the end.
    $scannable = get_post_stati( [ 'exclude_from_search' => false ] );
    $total = 0;

    foreach ( $post_types as $post_type ) {
        $count_obj = wp_count_posts( $post_type );
        if ( $count_obj ) {
            foreach ( get_object_vars( $count_obj ) as $status => $count_value ) {
                if ( isset( $scannable[ $status ] ) ) {
                    $total += (int) $count_value;
                }
            }
        }
    }

    return $total;
}

/**
 * Returns an array of WP_Post objects that contain the specified block.
 */
function fbps_get_posts_using_block( $block_name, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = fbps_get_query_limit();

    // The limit is the most posts one search may scan. An offset at or past
    // it is a terminal batch; the last batch before it is clamped so the scan
    // never runs past the limit. Callers read 'scanned' to tell a capped
    // scan from a genuine end of content.
    $batch_offset = absint( $batch_offset );
    if ( $batch_offset >= $limit ) {
        return [ 'posts' => [], 'has_more' => false, 'next_offset' => $batch_offset, 'scanned' => 0, 'limit' => $limit ];
    }
    $batch_size = min( absint( $batch_size ), $limit - $batch_offset );

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
    $processed = 0; // IDs actually examined; the timeout below can stop the loop early

    foreach ( $ids as $post_id ) {
        // Timeout protection
        if ( microtime( true ) - $start_time > 25 ) { // 25 second safeguard
            fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

        $processed++;

        $post_id = absint( $post_id ); // Extra validation

        // Use object cache for block detection with 5-minute TTL
        $cache_key = 'fbps_has_block_' . md5( $block_name . '_' . $post_id );
        $has_block_cached = wp_cache_get( $cache_key, 'find-blocks-patterns-shortcodes' );

        if ( false === $has_block_cached ) {
            $post = get_post( $post_id );
            // Use enhanced detection including variations
            $has_block_cached = fbps_detect_block_variations( $block_name, $post ) ? 'yes' : 'no';
            wp_cache_set( $cache_key, $has_block_cached, 'find-blocks-patterns-shortcodes', 300 ); // 5-minute cache
        }

        if ( 'yes' === $has_block_cached ) {
            $matches[] = get_post( $post_id );
        }
    }

    // If the 25-second guard stopped the loop, only $processed of the fetched
    // IDs were examined. scanned and next_offset are derived from that count so
    // the caller resumes at the first unexamined post instead of skipping the
    // rest of the batch - and a timeout on the last batch is not reported as a
    // complete scan. has_more is true after a timeout because this batch itself
    // still has posts to examine.
    $timed_out = $processed < count( $ids );

    return [
        'posts' => $matches,
        'has_more' => $timed_out ? $processed > 0 : ( count( $ids ) === $batch_size && ( $batch_offset + $batch_size ) < $limit ),
        'next_offset' => $batch_offset + $processed,
        'scanned' => $processed,
        'timed_out' => $timed_out,
        'limit' => $limit,
    ];
}

/**
 * Add admin menu page under "Tools".
 */
add_action( 'admin_menu', 'fbps_add_menu' );
function fbps_add_menu() {
    add_submenu_page(
        'tools.php',
        __( 'Find Blocks, Patterns & Shortcodes', 'find-blocks-patterns-shortcodes' ),
        __( 'Find Blocks, Patterns & Shortcodes', 'find-blocks-patterns-shortcodes' ),
        'use_find_blocks_patterns_shortcodes',
        'find-blocks-patterns-shortcodes',
        'fbps_render_admin_page'
    );
}

/**
 * Add security headers to the plugin's admin page.
 *
 * Hooked to load-{$page_hook}, which WordPress fires only when this screen is
 * about to render and before any output. This used to sit on admin_init and
 * compare get_current_screen()->id, which never worked for two reasons:
 * admin_init fires before set_current_screen(), so the screen was always null,
 * and the id it compared against was toplevel_page_* when a Tools submenu is
 * tools_page_* (the same string the asset enqueue already uses correctly).
 */
add_action( 'load-tools_page_find-blocks-patterns-shortcodes', 'fbps_set_security_headers' );
function fbps_set_security_headers() {
    if ( headers_sent() ) {
        return;
    }

    // Security headers
    // X-XSS-Protection is deliberately not sent: the legacy auditor it
    // enabled was removed from Chromium, and OWASP/MDN now advise against it.
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
}

/**
 * Renders the admin page: a tab list that shows one search form at a time
 * (block, synced pattern, shortcode), then the shared results area.
 */
function fbps_render_admin_page() {
    // Get all public post types
    $post_types = get_post_types( [ 'public' => true ], 'objects' );

    // Get all registered blocks
    $block_registry = WP_Block_Type_Registry::get_instance();
    $all_blocks = $block_registry->get_all_registered();
    ksort( $all_blocks ); // Sort alphabetically

    // Get all synced patterns
    $synced_patterns = fbps_get_synced_patterns();

    // Get all registered shortcodes
    $all_shortcodes = fbps_get_all_shortcodes();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Find Blocks, Patterns & Shortcodes', 'find-blocks-patterns-shortcodes' ); ?></h1>
        <?php // One form shows at a time. The markup follows the WAI-ARIA
              // tabs pattern: admin.js moves focus and selection between the
              // tabs with the arrow keys (roving tabindex) and toggles
              // `hidden` on the panels. Switching tabs never runs a search and
              // never clears the shared results area below. The three
              // progress regions stay outside the panels so that a search's
              // role="status" announcement is never inside a hidden panel.
              // The one Cancel button sits with them for the same reason:
              // only one search runs at a time, and its Cancel must stay
              // reachable after the user switches to another tab.
              //
              // The Patterns and Shortcodes panels take tabindex="0" because
              // each opens with a heading that cannot take focus, so Tab from
              // the tab list would skip it. The Blocks panel opens with a text
              // field, so it needs no extra Tab stop. ?>
        <div class="fbps-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Search type', 'find-blocks-patterns-shortcodes' ); ?>">
            <button type="button" role="tab" id="fbps-tab-block" class="fbps-tab" aria-selected="true" aria-controls="fbps-panel-block"><?php esc_html_e( 'Blocks', 'find-blocks-patterns-shortcodes' ); ?></button>
            <button type="button" role="tab" id="fbps-tab-pattern" class="fbps-tab" aria-selected="false" aria-controls="fbps-panel-pattern" tabindex="-1"><?php esc_html_e( 'Patterns', 'find-blocks-patterns-shortcodes' ); ?></button>
            <button type="button" role="tab" id="fbps-tab-shortcode" class="fbps-tab" aria-selected="false" aria-controls="fbps-panel-shortcode" tabindex="-1"><?php esc_html_e( 'Shortcodes', 'find-blocks-patterns-shortcodes' ); ?></button>
        </div>
        <div role="tabpanel" id="fbps-panel-block" class="fbps-tabpanel" aria-labelledby="fbps-tab-block">
        <div class="fbps-search-section" role="search" aria-label="<?php esc_attr_e( 'Search for block usage', 'find-blocks-patterns-shortcodes' ); ?>">
            <div class="fbps-search-field">
                <label for="fbps-block-name"><?php esc_html_e( 'Block Name:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <input type="text" id="fbps-block-name" class="fbps-search-input" placeholder="<?php esc_attr_e( 'e.g. core/paragraph', 'find-blocks-patterns-shortcodes' ); ?>">
                </div>
            </div>
            <div class="fbps-search-field">
                <label for="fbps-block-dropdown"><?php esc_html_e( 'Or select a block:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <select id="fbps-block-dropdown" class="fbps-block-dropdown">
                        <option value=""><?php esc_html_e( '-- Select a block --', 'find-blocks-patterns-shortcodes' ); ?></option>
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
            </div>
            <div class="fbps-search-field">
                <label for="fbps-class-name"><?php esc_html_e( 'CSS Class:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <input type="text" id="fbps-class-name" class="fbps-search-input" placeholder="<?php esc_attr_e( 'e.g. has-large-font-size', 'find-blocks-patterns-shortcodes' ); ?>">
                </div>
            </div>
            <div class="fbps-search-field">
                <label for="fbps-anchor-name"><?php esc_html_e( 'HTML Anchor:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <input type="text" id="fbps-anchor-name" class="fbps-search-input" aria-describedby="fbps-anchor-name-desc" placeholder="<?php esc_attr_e( 'e.g. my-section', 'find-blocks-patterns-shortcodes' ); ?>">
                    <small class="description" id="fbps-anchor-name-desc"><?php esc_html_e( 'Optional — search by class or anchor alone, or combine with a block name.', 'find-blocks-patterns-shortcodes' ); ?></small>
                </div>
            </div>
            <div class="fbps-search-field">
                <label for="fbps-post-types"><?php esc_html_e( 'Post Types:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <select id="fbps-post-types" class="fbps-post-types-select" aria-describedby="fbps-post-types-desc" multiple size="4">
                        <?php foreach ( $post_types as $post_type ) : ?>
                            <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                                <?php echo esc_html( $post_type->label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="description" id="fbps-post-types-desc"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'find-blocks-patterns-shortcodes' ); ?></small>
                </div>
            </div>
            <div class="fbps-search-actions">
                <button id="fbps-search-button" class="button button-primary"><?php esc_html_e( 'Search', 'find-blocks-patterns-shortcodes' ); ?></button>
            </div>
        </div>
        </div>
        <div role="tabpanel" id="fbps-panel-pattern" class="fbps-tabpanel" aria-labelledby="fbps-tab-pattern" tabindex="0" hidden>
        <h2><?php esc_html_e( 'Search for Synced Pattern Usage', 'find-blocks-patterns-shortcodes' ); ?></h2>
        <div class="fbps-search-section" role="search" aria-label="<?php esc_attr_e( 'Search for synced pattern usage', 'find-blocks-patterns-shortcodes' ); ?>">
            <div class="fbps-search-field">
                <label for="fbps-pattern-dropdown"><?php esc_html_e( 'Synced Pattern:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <select id="fbps-pattern-dropdown" class="fbps-pattern-dropdown">
                        <option value=""><?php esc_html_e( '-- Select a synced pattern --', 'find-blocks-patterns-shortcodes' ); ?></option>
                        <?php foreach ( $synced_patterns as $pattern ) : ?>
                            <option value="<?php echo esc_attr( $pattern->ID ); ?>">
                                <?php echo esc_html( $pattern->post_title ? $pattern->post_title : __( '(no title)', 'find-blocks-patterns-shortcodes' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ( empty( $synced_patterns ) ) : ?>
                            <option value="" disabled><?php esc_html_e( 'No synced patterns found', 'find-blocks-patterns-shortcodes' ); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="fbps-search-field">
                <label for="fbps-pattern-post-types"><?php esc_html_e( 'Post Types:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <select id="fbps-pattern-post-types" class="fbps-post-types-select" aria-describedby="fbps-pattern-post-types-desc" multiple size="4">
                        <?php foreach ( $post_types as $post_type ) : ?>
                            <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                                <?php echo esc_html( $post_type->label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="description" id="fbps-pattern-post-types-desc"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'find-blocks-patterns-shortcodes' ); ?></small>
                </div>
            </div>
            <div class="fbps-search-actions">
                <button id="fbps-pattern-search-button" class="button button-primary"><?php esc_html_e( 'Search Pattern', 'find-blocks-patterns-shortcodes' ); ?></button>
            </div>
        </div>
        </div>
        <div role="tabpanel" id="fbps-panel-shortcode" class="fbps-tabpanel" aria-labelledby="fbps-tab-shortcode" tabindex="0" hidden>
        <h2><?php esc_html_e( 'Search for Shortcode Usage', 'find-blocks-patterns-shortcodes' ); ?></h2>
        <div class="fbps-search-section" role="search" aria-label="<?php esc_attr_e( 'Search for shortcode usage', 'find-blocks-patterns-shortcodes' ); ?>">
            <div class="fbps-search-field">
                <label for="fbps-shortcode-dropdown"><?php esc_html_e( 'Shortcode:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <select id="fbps-shortcode-dropdown" class="fbps-shortcode-dropdown">
                        <option value=""><?php esc_html_e( '-- Select a shortcode --', 'find-blocks-patterns-shortcodes' ); ?></option>
                        <?php foreach ( $all_shortcodes as $shortcode ) : ?>
                            <option value="<?php echo esc_attr( $shortcode['tag'] ); ?>">
                                <?php echo esc_html( $shortcode['tag'] ); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ( empty( $all_shortcodes ) ) : ?>
                            <option value="" disabled><?php esc_html_e( 'No shortcodes found', 'find-blocks-patterns-shortcodes' ); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="fbps-search-field">
                <label for="fbps-shortcode-post-types"><?php esc_html_e( 'Post Types:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <div class="fbps-field-content">
                    <select id="fbps-shortcode-post-types" class="fbps-post-types-select" aria-describedby="fbps-shortcode-post-types-desc" multiple size="4">
                        <?php foreach ( $post_types as $post_type ) : ?>
                            <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                                <?php echo esc_html( $post_type->label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="description" id="fbps-shortcode-post-types-desc"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'find-blocks-patterns-shortcodes' ); ?></small>
                </div>
            </div>
            <div class="fbps-search-actions">
                <button id="fbps-shortcode-search-button" class="button button-primary"><?php esc_html_e( 'Search Shortcode', 'find-blocks-patterns-shortcodes' ); ?></button>
            </div>
        </div>
        </div>
        <div class="fbps-search-status">
            <div id="fbps-progress" class="fbps-progress-container" role="status" aria-live="polite"></div>
            <div id="fbps-pattern-progress" class="fbps-progress-container" role="status" aria-live="polite"></div>
            <div id="fbps-shortcode-progress" class="fbps-progress-container" role="status" aria-live="polite"></div>
            <button id="fbps-cancel-button" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'find-blocks-patterns-shortcodes' ); ?></button>
        </div>
        <hr style="margin: 30px 0;">
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
            <h2 style="margin: 0;"><?php esc_html_e( 'Results', 'find-blocks-patterns-shortcodes' ); ?></h2>
            <button id="fbps-export-button" class="button" style="display:none;"><?php esc_html_e( 'Export CSV', 'find-blocks-patterns-shortcodes' ); ?></button>
        </div>
        <fieldset class="fbps-column-toggles">
            <legend><?php esc_html_e( 'Show columns:', 'find-blocks-patterns-shortcodes' ); ?></legend>
            <label><input type="checkbox" class="fbps-col-toggle" value="title" checked> <?php esc_html_e( 'Title', 'find-blocks-patterns-shortcodes' ); ?></label>
            <label><input type="checkbox" class="fbps-col-toggle" value="type" checked> <?php esc_html_e( 'Type', 'find-blocks-patterns-shortcodes' ); ?></label>
            <label><input type="checkbox" class="fbps-col-toggle" value="date" checked> <?php esc_html_e( 'Modified', 'find-blocks-patterns-shortcodes' ); ?></label>
            <label><input type="checkbox" class="fbps-col-toggle" value="className"> <?php esc_html_e( 'CSS Class', 'find-blocks-patterns-shortcodes' ); ?></label>
            <label><input type="checkbox" class="fbps-col-toggle" value="anchor"> <?php esc_html_e( 'HTML Anchor', 'find-blocks-patterns-shortcodes' ); ?></label>
        </fieldset>
        <?php // These containers are NOT live regions. NVDA read the entire
              // results table aloud on every change when they were, because
              // aria-atomic re-announces the whole region and the table lives
              // inside it - 1778 characters for a nine-row table, five times
              // over on a 500-post search. Completion is announced instead by
              // the small role="status" progress region above, which exists
              // from page load and so announces reliably.
              //
              // role="region" and aria-label are added by admin.js only while a
              // container holds results, so empty containers stay out of the
              // landmark list. aria-label is prohibited on a div with no role,
              // so the label waits in data-region-label until it applies. ?>
        <div id="fbps-shortcode-search-results"
             class="fbps-results-container"
             data-region-label="<?php esc_attr_e( 'Shortcode Search Results', 'find-blocks-patterns-shortcodes' ); ?>">
        </div>
        <div id="fbps-pattern-search-results"
             class="fbps-results-container"
             data-region-label="<?php esc_attr_e( 'Pattern Search Results', 'find-blocks-patterns-shortcodes' ); ?>">
        </div>
        <div id="fbps-search-results"
             class="fbps-results-container"
             data-region-label="<?php esc_attr_e( 'Search Results', 'find-blocks-patterns-shortcodes' ); ?>">
        </div>
    </div>
    <?php
}

/**
 * Helper function to detect block variations.
 */
function fbps_detect_block_variations( $block_name, $post ) {
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
 * Detect if a post contains a block with a specific CSS class or HTML anchor.
 *
 * Parses Gutenberg block comment delimiters to extract JSON attributes
 * and checks for matching className or anchor values.
 *
 * @param WP_Post $post           The post to search.
 * @param string  $class_name     CSS class to search for (empty to skip).
 * @param string  $anchor_name    HTML anchor to search for (empty to skip).
 * @param string  $block_name     Optional block name to filter by.
 * @return bool True if a matching block is found.
 */
function fbps_detect_block_attribute( $post, $class_name = '', $anchor_name = '', $block_name = '' ) {
    if ( empty( $class_name ) && empty( $anchor_name ) ) {
        return false;
    }

    $content = $post->post_content;
    if ( empty( $content ) ) {
        return false;
    }

    // Match block comment delimiters with JSON attributes
    // e.g. <!-- wp:paragraph {"className":"my-class","anchor":"my-id"} -->
    if ( ! preg_match_all( '/<!--\s+wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s+(\{.*?\})\s+-->/i', $content, $matches, PREG_SET_ORDER ) ) {
        return false;
    }

    foreach ( $matches as $match ) {
        $matched_block = $match[1];
        $json_str      = $match[2];

        // If filtering by block name, check it matches
        if ( ! empty( $block_name ) && $matched_block !== $block_name ) {
            continue;
        }

        $attrs = json_decode( $json_str, true );
        if ( ! is_array( $attrs ) ) {
            continue;
        }

        // Check className (space-separated list, match whole words)
        if ( ! empty( $class_name ) && ! empty( $attrs['className'] ) ) {
            $classes = explode( ' ', $attrs['className'] );
            if ( in_array( $class_name, $classes, true ) ) {
                if ( empty( $anchor_name ) ) {
                    return true;
                }
                // If both class and anchor are specified, both must match on the same block
                if ( ! empty( $attrs['anchor'] ) && $attrs['anchor'] === $anchor_name ) {
                    return true;
                }
            }
        }

        // Check anchor (exact match)
        if ( ! empty( $anchor_name ) && empty( $class_name ) ) {
            if ( ! empty( $attrs['anchor'] ) && $attrs['anchor'] === $anchor_name ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Extract actual className and anchor values from a post's matching blocks.
 *
 * Unlike fbps_detect_block_attribute() which returns a boolean, this function
 * returns the actual attribute values for display in results tables.
 *
 * @param WP_Post $post        The post to extract from.
 * @param string  $class_name  CSS class filter (empty to skip).
 * @param string  $anchor_name HTML anchor filter (empty to skip).
 * @param string  $block_name  Optional block name filter.
 * @return array { className: string, anchor: string }
 */
function fbps_extract_block_attributes( $post, $class_name = '', $anchor_name = '', $block_name = '' ) {
	$result = [ 'className' => '', 'anchor' => '' ];

	$content = $post->post_content;
	if ( empty( $content ) ) {
		return $result;
	}

	if ( ! preg_match_all( '/<!--\s+wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s+(\{.*?\})\s+-->/i', $content, $matches, PREG_SET_ORDER ) ) {
		return $result;
	}

	$found_classes = [];
	$found_anchors = [];

	foreach ( $matches as $match ) {
		$matched_block = $match[1];
		$json_str      = $match[2];

		// If filtering by block name, check it matches
		if ( ! empty( $block_name ) && $matched_block !== $block_name ) {
			continue;
		}

		$attrs = json_decode( $json_str, true );
		if ( ! is_array( $attrs ) ) {
			continue;
		}

		$class_match  = false;
		$anchor_match = false;

		// Check className
		if ( ! empty( $attrs['className'] ) ) {
			if ( ! empty( $class_name ) ) {
				$classes = explode( ' ', $attrs['className'] );
				if ( in_array( $class_name, $classes, true ) ) {
					$class_match = true;
				}
			} else {
				$class_match = true;
			}
		}

		// Check anchor
		if ( ! empty( $attrs['anchor'] ) ) {
			if ( ! empty( $anchor_name ) ) {
				if ( $attrs['anchor'] === $anchor_name ) {
					$anchor_match = true;
				}
			} else {
				$anchor_match = true;
			}
		}

		// Apply same matching logic as fbps_detect_block_attribute
		$is_match = false;
		if ( ! empty( $class_name ) && ! empty( $anchor_name ) ) {
			$is_match = $class_match && $anchor_match;
		} elseif ( ! empty( $class_name ) ) {
			$is_match = $class_match;
		} elseif ( ! empty( $anchor_name ) ) {
			$is_match = $anchor_match;
		} else {
			// No filter — collect all attributes from matching blocks
			$is_match = true;
		}

		if ( $is_match ) {
			if ( ! empty( $attrs['className'] ) ) {
				foreach ( explode( ' ', $attrs['className'] ) as $cls ) {
					$found_classes[ $cls ] = true;
				}
			}
			if ( ! empty( $attrs['anchor'] ) ) {
				$found_anchors[ $attrs['anchor'] ] = true;
			}
		}
	}

	$result['className'] = implode( ' ', array_keys( $found_classes ) );
	$result['anchor']    = implode( ', ', array_keys( $found_anchors ) );

	return $result;
}

/**
 * Get posts containing blocks with a specific CSS class or HTML anchor.
 *
 * @param string $class_name   CSS class to search for.
 * @param string $anchor_name  HTML anchor to search for.
 * @param string $block_name   Optional block name to filter by.
 * @param array  $post_types   Post types to search.
 * @param int    $batch_offset Offset for batch processing.
 * @param int    $batch_size   Number of posts per batch.
 * @return array { posts: WP_Post[], has_more: bool, next_offset: int }
 */
function fbps_get_posts_with_attribute( $class_name = '', $anchor_name = '', $block_name = '', $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = fbps_get_query_limit();

    // The limit is the most posts one search may scan. An offset at or past
    // it is a terminal batch; the last batch before it is clamped so the scan
    // never runs past the limit. Callers read 'scanned' to tell a capped
    // scan from a genuine end of content.
    $batch_offset = absint( $batch_offset );
    if ( $batch_offset >= $limit ) {
        return [ 'posts' => [], 'has_more' => false, 'next_offset' => $batch_offset, 'scanned' => 0, 'limit' => $limit ];
    }
    $batch_size = min( absint( $batch_size ), $limit - $batch_offset );

    if ( empty( $post_types ) ) {
        $post_types = [ 'post', 'page' ];
    } else {
        $post_types = array_map( 'sanitize_key', (array) $post_types );
    }

    $ids = get_posts( [
        'post_type'              => $post_types,
        'posts_per_page'         => $batch_size,
        'offset'                 => $batch_offset,
        'post_status'            => 'any',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
    ] );

    $matches    = [];
    $start_time = microtime( true );
    $processed = 0; // IDs actually examined; the timeout below can stop the loop early

    foreach ( $ids as $post_id ) {
        if ( microtime( true ) - $start_time > 25 ) {
            fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

        $processed++;

        $post_id = absint( $post_id );

        $cache_key        = 'fbps_has_attr_' . md5( $class_name . '_' . $anchor_name . '_' . $block_name . '_' . $post_id );
        $has_attr_cached  = wp_cache_get( $cache_key, 'find-blocks-patterns-shortcodes' );

        if ( false === $has_attr_cached ) {
            $post             = get_post( $post_id );
            $has_attr_cached  = fbps_detect_block_attribute( $post, $class_name, $anchor_name, $block_name ) ? 'yes' : 'no';
            wp_cache_set( $cache_key, $has_attr_cached, 'find-blocks-patterns-shortcodes', 300 );
        }

        if ( 'yes' === $has_attr_cached ) {
            $matches[] = get_post( $post_id );
        }
    }

    // If the 25-second guard stopped the loop, only $processed of the fetched
    // IDs were examined. scanned and next_offset are derived from that count so
    // the caller resumes at the first unexamined post instead of skipping the
    // rest of the batch - and a timeout on the last batch is not reported as a
    // complete scan. has_more is true after a timeout because this batch itself
    // still has posts to examine.
    $timed_out = $processed < count( $ids );

    return [
        'posts'       => $matches,
        'has_more'    => $timed_out ? $processed > 0 : ( count( $ids ) === $batch_size && ( $batch_offset + $batch_size ) < $limit ),
        'next_offset' => $batch_offset + $processed,
        'scanned'    => $processed,
        'timed_out'    => $timed_out,
        'limit'    => $limit,
    ];
}

/**
 * AJAX handler for searching block usage.
 */
add_action( 'wp_ajax_fbps_search_block', 'fbps_ajax_search_block' );
function fbps_ajax_search_block() {
    try {
        check_ajax_referer( 'fbps_search_nonce' );

        if ( ! current_user_can( 'use_find_blocks_patterns_shortcodes' ) ) {
            fbps_log_security_event( 'unauthorized_access', [ 'capability' => 'use_find_blocks_patterns_shortcodes' ] );
            throw new Exception( 'unauthorized' );
        }

        // Proper input handling with wp_unslash()
        $batch_offset = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;
        $block = isset( $_POST['block_name'] ) ? sanitize_text_field( wp_unslash( $_POST['block_name'] ) ) : '';
        $class_name = isset( $_POST['class_name'] ) ? sanitize_text_field( wp_unslash( $_POST['class_name'] ) ) : '';
        $anchor_name = isset( $_POST['anchor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_name'] ) ) : '';
        $post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [];

        // Rate limiting with IP tracking
        $rate_check = fbps_check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            throw new Exception( 'rate_limit' );
        }

        $has_attribute_search = ! empty( $class_name ) || ! empty( $anchor_name );

        if ( empty( $block ) && ! $has_attribute_search ) {
            throw new Exception( 'empty_input' );
        }

        // Validate block name if provided
        if ( ! empty( $block ) ) {
            $validation = fbps_validate_block_name( $block );
            if ( is_wp_error( $validation ) ) {
                fbps_log_security_event( 'invalid_input', [
                    'block_name' => $block,
                    'error' => $validation->get_error_message()
                ] );
                throw new Exception( 'invalid_input' );
            }

            $namespace_check = fbps_validate_block_namespace( $block );
            if ( is_wp_error( $namespace_check ) ) {
                fbps_log_security_event( 'suspicious_namespace', [ 'block_name' => $block ] );
            }
        }

        // Validate class name if provided
        if ( ! empty( $class_name ) ) {
            $class_validation = fbps_validate_attribute_search( $class_name );
            if ( is_wp_error( $class_validation ) ) {
                fbps_log_security_event( 'invalid_input', [ 'class_name' => $class_name ] );
                throw new Exception( 'invalid_class' );
            }
        }

        // Validate anchor name if provided
        if ( ! empty( $anchor_name ) ) {
            $anchor_validation = fbps_validate_attribute_search( $anchor_name );
            if ( is_wp_error( $anchor_validation ) ) {
                fbps_log_security_event( 'invalid_input', [ 'anchor_name' => $anchor_name ] );
                throw new Exception( 'invalid_anchor' );
            }
        }

        // Get total count on first request for accurate progress
        $total_posts = 0;
        if ( $batch_offset === 0 ) {
            $total_posts = fbps_get_total_posts_count( $post_types );
        }

        // Use attribute search when class/anchor is provided, otherwise standard block search
        if ( $has_attribute_search ) {
            $search_result = fbps_get_posts_with_attribute( $class_name, $anchor_name, $block, $post_types, $batch_offset, FBPS_BATCH_SIZE );
        } else {
            $search_result = fbps_get_posts_using_block( $block, $post_types, $batch_offset, FBPS_BATCH_SIZE );
        }

        // A batch that timed out before examining a single post would hand the
        // client the same offset back and loop forever. Surface it instead.
        if ( ! empty( $search_result['timed_out'] ) && 0 === $search_result['scanned'] ) {
            throw new Exception( 'timeout' );
        }
        $results = [];

        foreach ( $search_result['posts'] as $post ) {
            // Get the post date (use modified if available, otherwise published)
            $post_date = ! empty( $post->post_modified ) ? $post->post_modified : $post->post_date;

            // Extract block attributes for display columns
            $attrs = fbps_extract_block_attributes( $post, $class_name, $anchor_name, $block );

            $results[] = [
                'id'        => absint( $post->ID ),
                'title'     => get_the_title( $post ),
                'edit_link' => esc_url_raw( get_edit_post_link( $post, 'raw' ) ),
                'view_link' => esc_url_raw( get_permalink( $post ) ),
                'type'      => sanitize_key( $post->post_type ),
                'date'      => $post_date,
                // Formatted server-side with the site's date format and locale;
                // the raw value above stays for sorting.
                'date_display' => mysql2date( get_option( 'date_format' ), $post_date ),
                'className' => $attrs['className'],
                'anchor'    => $attrs['anchor'],
            ];
        }

        // Calculate accurate progress
        $progress = 0;
        if ( $total_posts > 0 ) {
            $progress = min( 100, ( ( $batch_offset + 100 ) / $total_posts ) * 100 );
        } elseif ( ! $search_result['has_more'] ) {
            $progress = 100;
        }

        $response = [
            'results' => $results,
            'has_more' => $search_result['has_more'],
            'next_offset' => $search_result['next_offset'],
            'progress' => round( $progress, 1 ),
        ];

        // On the last batch, say whether the scan stopped at the query limit
        // rather than at the end of the content. Batches walk oldest-first, so a
        // capped scan never reaches the newest posts; without this the UI would
        // report a confident "no results" for a block used only in recent content.
        if ( ! $search_result['has_more'] ) {
            $scanned_total = $batch_offset + $search_result['scanned'];
            $total_all     = fbps_get_total_posts_count( $post_types );
            $response['scanned']     = $scanned_total;
            $response['total_posts'] = $total_all;
            $response['truncated']   = ( $scanned_total >= $search_result['limit'] ) && ( $total_all > $scanned_total );
        }

        if ( $batch_offset === 0 ) {
            $response['total_posts'] = $total_posts;
        }

        wp_send_json_success( $response );

    } catch ( Exception $e ) {
        // Generic error messages to prevent information disclosure
        $error_messages = [
            'unauthorized'   => __( 'Access denied', 'find-blocks-patterns-shortcodes' ),
            'rate_limit'     => __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ),
            'timeout'      => __( 'The search timed out before it could examine any posts. Try fewer post types.', 'find-blocks-patterns-shortcodes' ),
            'empty_input'    => __( 'Enter a block name, CSS class, or HTML anchor', 'find-blocks-patterns-shortcodes' ),
            'invalid_input'  => __( 'Invalid block name format. Use: namespace/block-name', 'find-blocks-patterns-shortcodes' ),
            'invalid_class'  => __( 'Invalid CSS class format. Use only letters, numbers, hyphens, and underscores.', 'find-blocks-patterns-shortcodes' ),
            'invalid_anchor' => __( 'Invalid anchor format. Use only letters, numbers, hyphens, and underscores.', 'find-blocks-patterns-shortcodes' ),
        ];

        $message = isset( $error_messages[ $e->getMessage() ] ) ? $error_messages[ $e->getMessage() ] : __( 'An error occurred', 'find-blocks-patterns-shortcodes' );
        wp_send_json_error( $message );
    }
}

/**
 * AJAX handler for searching synced pattern usage.
 */
add_action( 'wp_ajax_fbps_search_pattern', 'fbps_ajax_search_pattern' );
function fbps_ajax_search_pattern() {
    try {
        check_ajax_referer( 'fbps_search_nonce' );

        if ( ! current_user_can( 'use_find_blocks_patterns_shortcodes' ) ) {
            fbps_log_security_event( 'unauthorized_access', [ 'capability' => 'use_find_blocks_patterns_shortcodes' ] );
            throw new Exception( 'unauthorized' );
        }

        // Proper input handling with wp_unslash()
        $batch_offset = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;
        $pattern_id = isset( $_POST['pattern_id'] ) ? absint( $_POST['pattern_id'] ) : 0;
        $post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [];

        // Rate limiting with IP tracking
        $rate_check = fbps_check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            throw new Exception( 'rate_limit' );
        }

        if ( empty( $pattern_id ) ) {
            throw new Exception( 'empty_input' );
        }

        // Verify the pattern exists and is a wp_block post type
        $pattern = get_post( $pattern_id );
        if ( ! $pattern || $pattern->post_type !== 'wp_block' ) {
            fbps_log_security_event( 'invalid_pattern', [ 'pattern_id' => $pattern_id ] );
            throw new Exception( 'invalid_pattern' );
        }

        // Get total count on first request for accurate progress
        $total_posts = 0;
        if ( $batch_offset === 0 ) {
            $total_posts = fbps_get_total_posts_count( $post_types );
        }

        $search_result = fbps_get_posts_using_pattern( $pattern_id, $post_types, $batch_offset, FBPS_BATCH_SIZE );

        // A batch that timed out before examining a single post would hand the
        // client the same offset back and loop forever. Surface it instead.
        if ( ! empty( $search_result['timed_out'] ) && 0 === $search_result['scanned'] ) {
            throw new Exception( 'timeout' );
        }
        $results = [];

        foreach ( $search_result['posts'] as $post ) {
            // Get the post date (use modified if available, otherwise published)
            $post_date = ! empty( $post->post_modified ) ? $post->post_modified : $post->post_date;

            $results[] = [
                'id'        => absint( $post->ID ),
                'title'     => get_the_title( $post ),
                'edit_link' => esc_url_raw( get_edit_post_link( $post, 'raw' ) ),
                'view_link' => esc_url_raw( get_permalink( $post ) ),
                'type'      => sanitize_key( $post->post_type ),
                'date'      => $post_date,
                // Formatted server-side with the site's date format and locale;
                // the raw value above stays for sorting.
                'date_display' => mysql2date( get_option( 'date_format' ), $post_date ),
            ];
        }

        // Calculate accurate progress
        $progress = 0;
        if ( $total_posts > 0 ) {
            $progress = min( 100, ( ( $batch_offset + 100 ) / $total_posts ) * 100 );
        } elseif ( ! $search_result['has_more'] ) {
            $progress = 100;
        }

        $response = [
            'results' => $results,
            'has_more' => $search_result['has_more'],
            'next_offset' => $search_result['next_offset'],
            'progress' => round( $progress, 1 ),
        ];

        // On the last batch, say whether the scan stopped at the query limit
        // rather than at the end of the content. Batches walk oldest-first, so a
        // capped scan never reaches the newest posts; without this the UI would
        // report a confident "no results" for a block used only in recent content.
        if ( ! $search_result['has_more'] ) {
            $scanned_total = $batch_offset + $search_result['scanned'];
            $total_all     = fbps_get_total_posts_count( $post_types );
            $response['scanned']     = $scanned_total;
            $response['total_posts'] = $total_all;
            $response['truncated']   = ( $scanned_total >= $search_result['limit'] ) && ( $total_all > $scanned_total );
        }

        if ( $batch_offset === 0 ) {
            $response['total_posts'] = $total_posts;
        }

        wp_send_json_success( $response );

    } catch ( Exception $e ) {
        // Generic error messages to prevent information disclosure
        $error_messages = [
            'unauthorized'    => __( 'Access denied', 'find-blocks-patterns-shortcodes' ),
            'rate_limit'      => __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ),
            'timeout'       => __( 'The search timed out before it could examine any posts. Try fewer post types.', 'find-blocks-patterns-shortcodes' ),
            'empty_input'     => __( 'Pattern ID is required', 'find-blocks-patterns-shortcodes' ),
            'invalid_pattern' => __( 'Invalid pattern ID', 'find-blocks-patterns-shortcodes' ),
        ];

        $message = isset( $error_messages[ $e->getMessage() ] ) ? $error_messages[ $e->getMessage() ] : __( 'An error occurred', 'find-blocks-patterns-shortcodes' );
        wp_send_json_error( $message );
    }
}

/**
 * AJAX handler for searching shortcode usage.
 */
add_action( 'wp_ajax_fbps_search_shortcode', 'fbps_ajax_search_shortcode' );
function fbps_ajax_search_shortcode() {
	try {
		check_ajax_referer( 'fbps_search_nonce' );

		if ( ! current_user_can( 'use_find_blocks_patterns_shortcodes' ) ) {
			fbps_log_security_event( 'unauthorized_access', [ 'capability' => 'use_find_blocks_patterns_shortcodes' ] );
			throw new Exception( 'unauthorized' );
		}

		// Proper input handling with wp_unslash()
		$batch_offset = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;
		$shortcode_name = isset( $_POST['shortcode_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shortcode_name'] ) ) : '';
		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [];

		// Rate limiting with IP tracking
		$rate_check = fbps_check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			throw new Exception( 'rate_limit' );
		}

		if ( empty( $shortcode_name ) ) {
			throw new Exception( 'empty_input' );
		}

		// Enhanced validation
		$validation = fbps_validate_shortcode_name( $shortcode_name );
		if ( is_wp_error( $validation ) ) {
			fbps_log_security_event( 'invalid_input', [
				'shortcode_name' => $shortcode_name,
				'error' => $validation->get_error_message()
			] );
			throw new Exception( 'invalid_input' );
		}

		// Verify the shortcode is registered
		global $shortcode_tags;
		if ( ! isset( $shortcode_tags[ $shortcode_name ] ) ) {
			fbps_log_security_event( 'unregistered_shortcode', [ 'shortcode_name' => $shortcode_name ] );
			// Continue anyway but log the event - shortcode might have been used before being unregistered
		}

	// Get total count on first request for accurate progress
	$total_posts = 0;
		if ( $batch_offset === 0 ) {
			$total_posts = fbps_get_total_posts_count( $post_types );
		}

		$search_result = fbps_get_posts_using_shortcode( $shortcode_name, $post_types, $batch_offset, FBPS_BATCH_SIZE );

		// A batch that timed out before examining a single post would hand the
		// client the same offset back and loop forever. Surface it instead.
		if ( ! empty( $search_result['timed_out'] ) && 0 === $search_result['scanned'] ) {
			throw new Exception( 'timeout' );
		}

		$results = [];

		foreach ( $search_result['posts'] as $post ) {
			// Get the post date (use modified if available, otherwise published)
			$post_date = ! empty( $post->post_modified ) ? $post->post_modified : $post->post_date;

			$results[] = [
				'id'        => absint( $post->ID ),
				'title'     => get_the_title( $post ),
				'edit_link' => esc_url_raw( get_edit_post_link( $post, 'raw' ) ),
				'view_link' => esc_url_raw( get_permalink( $post ) ),
				'type'      => sanitize_key( $post->post_type ),
				'date'      => $post_date,
				// Formatted server-side with the site's date format and locale;
				// the raw value above stays for sorting.
				'date_display' => mysql2date( get_option( 'date_format' ), $post_date ),
			];
		}

		// Calculate accurate progress
		$progress = 0;
		if ( $total_posts > 0 ) {
			$progress = min( 100, ( ( $batch_offset + 100 ) / $total_posts ) * 100 );
		} elseif ( ! $search_result['has_more'] ) {
			$progress = 100;
		}

		$response = [
			'results' => $results,
			'has_more' => $search_result['has_more'],
			'next_offset' => $search_result['next_offset'],
			'progress' => round( $progress, 1 ),
		];

		// On the last batch, say whether the scan stopped at the query limit
		// rather than at the end of the content. Batches walk oldest-first, so a
		// capped scan never reaches the newest posts; without this the UI would
		// report a confident "no results" for a block used only in recent content.
		if ( ! $search_result['has_more'] ) {
			$scanned_total = $batch_offset + $search_result['scanned'];
			$total_all     = fbps_get_total_posts_count( $post_types );
			$response['scanned']     = $scanned_total;
			$response['total_posts'] = $total_all;
			$response['truncated']   = ( $scanned_total >= $search_result['limit'] ) && ( $total_all > $scanned_total );
		}

		if ( $batch_offset === 0 ) {
			$response['total_posts'] = $total_posts;
		}

		wp_send_json_success( $response );


	} catch ( Exception $e ) {
		// Generic error messages to prevent information disclosure
		$error_messages = [
			'unauthorized'  => __( 'Access denied', 'find-blocks-patterns-shortcodes' ),
			'rate_limit'    => __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ),
			'timeout'     => __( 'The search timed out before it could examine any posts. Try fewer post types.', 'find-blocks-patterns-shortcodes' ),
			'empty_input'   => __( 'Shortcode name is required', 'find-blocks-patterns-shortcodes' ),
			'invalid_input' => __( 'Invalid shortcode name format', 'find-blocks-patterns-shortcodes' ),
		];

		$message = isset( $error_messages[ $e->getMessage() ] ) ? $error_messages[ $e->getMessage() ] : __( 'An error occurred', 'find-blocks-patterns-shortcodes' );
		wp_send_json_error( $message );
	}
}

/**
 * AJAX handler for refreshing nonce.
 */
add_action( 'wp_ajax_fbps_refresh_nonce', 'fbps_ajax_refresh_nonce' );
function fbps_ajax_refresh_nonce() {
    if ( ! current_user_can( 'use_find_blocks_patterns_shortcodes' ) ) {
        wp_send_json_error();
    }

    wp_send_json_success( [ 'nonce' => wp_create_nonce( 'fbps_search_nonce' ) ] );
}

/**
 * WP-CLI command for searching block usage.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class FBPS_CLI {
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
         * : Maximum number of posts to scan, oldest first (default: the fbps_query_limit value, 500; hard cap 1000)
         *
         * ## EXAMPLES
         *
         *     wp fbps search core/paragraph
         *     wp fbps search core/gallery --post-type=post,page --format=csv
         *     wp fbps search acf/testimonial --format=ids
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function search( $args, $assoc_args ) {
            list( $block_name ) = $args;

            // Validate block name
            $validation = fbps_validate_block_name( $block_name );
            if ( is_wp_error( $validation ) ) {
                WP_CLI::error( $validation->get_error_message() );
            }

            // Parse post types
            $post_types = isset( $assoc_args['post-type'] ) ? explode( ',', $assoc_args['post-type'] ) : [ 'post', 'page' ];
            $post_types = array_map( 'trim', $post_types );

            // Parse format
            $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

            // Parse limit
            // --limit is the number of posts to scan, the same thing the fbps_query_limit
            // filter controls, so it is applied through that filter for this run. The
            // accessor still enforces the 1000 hard cap and the >= 1 floor.
            if ( isset( $assoc_args['limit'] ) ) {
                $cli_limit = absint( $assoc_args['limit'] );
                add_filter( 'fbps_query_limit', function () use ( $cli_limit ) { return $cli_limit; } );
            }
            $limit = fbps_get_query_limit();

            WP_CLI::log( sprintf( 'Searching for block: %s', $block_name ) );
            WP_CLI::log( sprintf( 'Post types: %s', implode( ', ', $post_types ) ) );

            // Search in batches
            $all_matches = [];
            $offset = 0;
            $scanned = 0;

            do {
                $result = fbps_get_posts_using_block( $block_name, $post_types, $offset, FBPS_BATCH_SIZE );
                $all_matches = array_merge( $all_matches, $result['posts'] );
                $scanned += $result['scanned'];

                WP_CLI::log( sprintf( 'Searched %d posts... found %d matches so far', $scanned, count( $all_matches ) ) );

                $offset = $result['next_offset'];
            } while ( $result['has_more'] );

            $total_all = fbps_get_total_posts_count( $post_types );
            if ( $scanned >= $limit && $total_all > $scanned ) {
                WP_CLI::warning( sprintf( 'Scanned the first %d of %d posts (oldest first). Use --limit, up to 1000, to scan more.', $scanned, $total_all ) );
            }

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
         *     wp fbps clear-cache
         */
        public function clear_cache() {
            global $wpdb;
            $deleted = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_fbps_has_block_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_fbps_has_block_' ) . '%'
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
         *     wp fbps logs
         *     wp fbps logs --limit=100 --format=csv
         */
        public function logs( $args, $assoc_args ) {
            $logs = get_option( 'fbps_security_logs', [] );

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

    WP_CLI::add_command( 'fbps', 'FBPS_CLI' );
}

