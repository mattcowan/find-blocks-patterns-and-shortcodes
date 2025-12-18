<?php
/**
 * Plugin Name: Find Blocks, Patterns & Shortcodes
 * Description: A powerful finder tool to audit your site. Locate instances of any Block, Pattern, or Shortcode and export the full usage report to CSV.
 * Version:     1.0.0
 * Author:      Matthew Cowan
 * Author URI: https://mnc4.com
 * Text Domain: find-blocks-patterns-shortcodes
 * License:     GPL2
 * Requires at least: 5.0
 * Requires PHP: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version constant
define( 'FBPS_VERSION', '1.0.0' );

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
				'searching'         => __( 'Searching', 'find-blocks-patterns-shortcodes' ),
				/* translators: %d: number of results found */
				'searchingProgress' => __( 'Searching... %d results found so far', 'find-blocks-patterns-shortcodes' ),
				'result'                => __( 'result', 'find-blocks-patterns-shortcodes' ),
				'results'               => __( 'results', 'find-blocks-patterns-shortcodes' ),
				'foundSoFar'            => __( 'found so far...', 'find-blocks-patterns-shortcodes' ),
				'found'                 => __( 'found', 'find-blocks-patterns-shortcodes' ),
				'title'                 => __( 'Title', 'find-blocks-patterns-shortcodes' ),
				'type'                  => __( 'Type', 'find-blocks-patterns-shortcodes' ),
				'date'                  => __( 'Date', 'find-blocks-patterns-shortcodes' ),
				'actions'               => __( 'Actions', 'find-blocks-patterns-shortcodes' ),
				'view'                  => __( 'View', 'find-blocks-patterns-shortcodes' ),
				'edit'                  => __( 'Edit', 'find-blocks-patterns-shortcodes' ),
				'error'                 => __( 'Error:', 'find-blocks-patterns-shortcodes' ),
				'invalidResponseFormat' => __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ),
				'unknownError'          => __( 'Unknown error', 'find-blocks-patterns-shortcodes' ),
				'networkError'          => __( 'Network error. Please try again.', 'find-blocks-patterns-shortcodes' ),
				'searchCancelled'       => __( 'Search cancelled.', 'find-blocks-patterns-shortcodes' ),
				'noBlockResults'        => __( 'No content found using that block.', 'find-blocks-patterns-shortcodes' ),
				'noPatternResults'      => __( 'No content found using that synced pattern.', 'find-blocks-patterns-shortcodes' ),
				'noShortcodeResults'    => __( 'No content found using that shortcode.', 'find-blocks-patterns-shortcodes' ),
				'selectPattern'         => __( 'Please select a synced pattern', 'find-blocks-patterns-shortcodes' ),
				'selectShortcode'       => __( 'Please select a shortcode', 'find-blocks-patterns-shortcodes' ),
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
function fbps_check_rate_limit() {
    $user_id = get_current_user_id();
    $client_ip = fbps_get_client_ip();

    // Track by both user ID and IP
    $rate_limit_keys = [
        'user_' . absint( $user_id ) => 30,  // 30 requests per minute per user
        'ip_' . md5( $client_ip ) => 50,     // 50 requests per minute per IP
    ];

    foreach ( $rate_limit_keys as $key => $max_requests ) {
        $full_key = 'fbps_rate_limit_' . sanitize_key( $key );
        $requests = get_transient( $full_key );

        // Ensure we're working with integers only (object injection prevention)
        $requests = absint( $requests );

        if ( $requests > $max_requests ) {
            fbps_log_security_event( 'rate_limit_exceeded', [
                'key' => $key,
                'requests' => $requests,
                'limit' => $max_requests
            ] );
            return new WP_Error( 'rate_limit', __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ) );
        }

        set_transient( $full_key, $requests + 1, MINUTE_IN_SECONDS );
    }

    return true;
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
 * Returns an array of WP_Post objects that contain the specified synced pattern.
 */
function fbps_get_posts_using_pattern( $pattern_id, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = absint( apply_filters( 'fbps_query_limit', 500 ) );
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
            fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

        $post_id = absint( $post_id ); // Extra validation

        // Use object cache for pattern detection with 5-minute TTL
        $cache_key = 'fbps_has_pattern_' . md5( $pattern_id . '_' . $post_id );
        $has_pattern_cached = wp_cache_get( $cache_key, 'find-blocks-patterns-shortcodes' );

        if ( false === $has_pattern_cached ) {
            $post = get_post( $post_id );
            // Check if post content contains the pattern reference
            $has_pattern_cached = preg_match( $ref_pattern, $post->post_content ) ? 'yes' : 'no';
            wp_cache_set( $cache_key, $has_pattern_cached, 'find-blocks-patterns-shortcodes', 300 ); // 5-minute cache
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
	$limit = absint( apply_filters( 'fbps_query_limit', 500 ) );
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
			fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
			break;
		}

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

	return [
		'posts' => $matches,
		'has_more' => count( $ids ) === $batch_size,
		'next_offset' => $batch_offset + $batch_size,
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

    $count = wp_count_posts();
    $total = 0;

    foreach ( $post_types as $post_type ) {
        $count_obj = wp_count_posts( $post_type );
        if ( $count_obj ) {
            // Sum all statuses
            foreach ( get_object_vars( $count_obj ) as $status => $count_value ) {
                $total += (int) $count_value;
            }
        }
    }

    return $total;
}

/**
 * Returns an array of WP_Post objects that contain the specified block.
 */
function fbps_get_posts_using_block( $block_name, $post_types = [], $batch_offset = 0, $batch_size = 100 ) {
    $limit = absint( apply_filters( 'fbps_query_limit', 500 ) );
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
            fbps_log_security_event( 'query_timeout', [ 'processed' => count( $matches ), 'batch_offset' => $batch_offset ] );
            break;
        }

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

    return [
        'posts' => $matches,
        'has_more' => count( $ids ) === $batch_size,
        'next_offset' => $batch_offset + $batch_size,
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
 * Add security headers to admin page.
 */
add_action( 'admin_init', 'fbps_set_security_headers' );
function fbps_set_security_headers() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_find-blocks-patterns-shortcodes' ) {
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
        <div role="search" aria-label="<?php esc_attr_e( 'Search for block usage', 'find-blocks-patterns-shortcodes' ); ?>">
            <div class="fbps-search-field">
                <label for="fbps-block-name"><?php esc_html_e( 'Block Name:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <input type="text" id="fbps-block-name" class="fbps-search-input" placeholder="<?php esc_attr_e( 'e.g. core/paragraph', 'find-blocks-patterns-shortcodes' ); ?>">
            </div>
            <div class="fbps-search-field">
                <label for="fbps-block-dropdown"><?php esc_html_e( 'Or select from available blocks:', 'find-blocks-patterns-shortcodes' ); ?></label>
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
            <div class="fbps-search-field">
                <label for="fbps-post-types"><?php esc_html_e( 'Post Types:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <select id="fbps-post-types" class="fbps-post-types-select" multiple size="4">
                    <?php foreach ( $post_types as $post_type ) : ?>
                        <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                            <?php echo esc_html( $post_type->label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'find-blocks-patterns-shortcodes' ); ?></small>
            </div>
            <div class="fbps-search-actions">
                <button id="fbps-search-button" class="button button-primary"><?php esc_html_e( 'Search', 'find-blocks-patterns-shortcodes' ); ?></button>
                <button id="fbps-cancel-button" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'find-blocks-patterns-shortcodes' ); ?></button>
            </div>
        </div>
        <div id="fbps-progress" class="fbps-progress-container" role="status" aria-live="polite"></div>
        <hr style="margin: 30px 0;">
        <h2><?php esc_html_e( 'Search for Synced Pattern Usage', 'find-blocks-patterns-shortcodes' ); ?></h2>
        <div role="search" aria-label="<?php esc_attr_e( 'Search for synced pattern usage', 'find-blocks-patterns-shortcodes' ); ?>">
            <div class="fbps-search-field">
                <label for="fbps-pattern-dropdown"><?php esc_html_e( 'Select a synced pattern:', 'find-blocks-patterns-shortcodes' ); ?></label>
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
            <div class="fbps-search-field">
                <label for="fbps-pattern-post-types"><?php esc_html_e( 'Post Types:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <select id="fbps-pattern-post-types" class="fbps-post-types-select" multiple size="4">
                    <?php foreach ( $post_types as $post_type ) : ?>
                        <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                            <?php echo esc_html( $post_type->label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'find-blocks-patterns-shortcodes' ); ?></small>
            </div>
            <div class="fbps-search-actions">
                <button id="fbps-pattern-search-button" class="button button-primary"><?php esc_html_e( 'Search Pattern', 'find-blocks-patterns-shortcodes' ); ?></button>
                <button id="fbps-pattern-cancel-button" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'find-blocks-patterns-shortcodes' ); ?></button>
            </div>
        </div>
        <div id="fbps-pattern-progress" class="fbps-progress-container" role="status" aria-live="polite"></div>
        <hr style="margin: 30px 0;">
        <h2><?php esc_html_e( 'Search for Shortcode Usage', 'find-blocks-patterns-shortcodes' ); ?></h2>
        <div role="search" aria-label="<?php esc_attr_e( 'Search for shortcode usage', 'find-blocks-patterns-shortcodes' ); ?>">
            <div class="fbps-search-field">
                <label for="fbps-shortcode-dropdown"><?php esc_html_e( 'Select a shortcode:', 'find-blocks-patterns-shortcodes' ); ?></label>
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
            <div class="fbps-search-field">
                <label for="fbps-shortcode-post-types"><?php esc_html_e( 'Post Types:', 'find-blocks-patterns-shortcodes' ); ?></label>
                <select id="fbps-shortcode-post-types" class="fbps-post-types-select" multiple size="4">
                    <?php foreach ( $post_types as $post_type ) : ?>
                        <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( in_array( $post_type->name, [ 'post', 'page' ], true ) ); ?>>
                            <?php echo esc_html( $post_type->label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple', 'find-blocks-patterns-shortcodes' ); ?></small>
            </div>
            <div class="fbps-search-actions">
                <button id="fbps-shortcode-search-button" class="button button-primary"><?php esc_html_e( 'Search Shortcode', 'find-blocks-patterns-shortcodes' ); ?></button>
                <button id="fbps-shortcode-cancel-button" class="button" style="display:none;"><?php esc_html_e( 'Cancel', 'find-blocks-patterns-shortcodes' ); ?></button>
            </div>
        </div>
        <div id="fbps-shortcode-progress" class="fbps-progress-container" role="status" aria-live="polite"></div>
        <hr style="margin: 30px 0;">
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
            <h2 style="margin: 0;"><?php esc_html_e( 'Results', 'find-blocks-patterns-shortcodes' ); ?></h2>
            <button id="fbps-export-button" class="button" style="display:none;"><?php esc_html_e( 'Export CSV', 'find-blocks-patterns-shortcodes' ); ?></button>
        </div>
        <div id="fbps-shortcode-search-results"
             class="fbps-results-container"
             role="region"
             aria-live="polite"
             aria-atomic="true"
             aria-label="<?php esc_attr_e( 'Shortcode Search Results', 'find-blocks-patterns-shortcodes' ); ?>">
        </div>
        <div id="fbps-pattern-search-results"
             class="fbps-results-container"
             role="region"
             aria-live="polite"
             aria-atomic="true"
             aria-label="<?php esc_attr_e( 'Pattern Search Results', 'find-blocks-patterns-shortcodes' ); ?>">
        </div>
        <div id="fbps-search-results"
             class="fbps-results-container"
             role="region"
             aria-live="polite"
             aria-atomic="true"
             aria-label="<?php esc_attr_e( 'Search Results', 'find-blocks-patterns-shortcodes' ); ?>">
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

        // Rate limiting with IP tracking
        $rate_check = fbps_check_rate_limit();
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
        $validation = fbps_validate_block_name( $block );
        if ( is_wp_error( $validation ) ) {
            fbps_log_security_event( 'invalid_input', [
                'block_name' => $block,
                'error' => $validation->get_error_message()
            ] );
            throw new Exception( 'invalid_input' );
        }

        // Validate block namespace against registered blocks
        $namespace_check = fbps_validate_block_namespace( $block );
        if ( is_wp_error( $namespace_check ) ) {
            fbps_log_security_event( 'suspicious_namespace', [ 'block_name' => $block ] );
            // Continue anyway but log the event
        }

        // Get total count on first request for accurate progress
        $total_posts = 0;
        if ( $batch_offset === 0 ) {
            $total_posts = fbps_get_total_posts_count( $post_types );
        }

        $search_result = fbps_get_posts_using_block( $block, $post_types, $batch_offset, 100 );
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

        if ( $batch_offset === 0 ) {
            $response['total_posts'] = $total_posts;
        }

        wp_send_json_success( $response );

    } catch ( Exception $e ) {
        // Generic error messages to prevent information disclosure
        $error_messages = [
            'unauthorized'  => __( 'Access denied', 'find-blocks-patterns-shortcodes' ),
            'rate_limit'    => __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ),
            'empty_input'   => __( 'Block name is required', 'find-blocks-patterns-shortcodes' ),
            'invalid_input' => __( 'Invalid block name format. Use: namespace/block-name', 'find-blocks-patterns-shortcodes' ),
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

        // Rate limiting with IP tracking
        $rate_check = fbps_check_rate_limit();
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
            fbps_log_security_event( 'invalid_pattern', [ 'pattern_id' => $pattern_id ] );
            throw new Exception( 'invalid_pattern' );
        }

        // Get total count on first request for accurate progress
        $total_posts = 0;
        if ( $batch_offset === 0 ) {
            $total_posts = fbps_get_total_posts_count( $post_types );
        }

        $search_result = fbps_get_posts_using_pattern( $pattern_id, $post_types, $batch_offset, 100 );
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

        if ( $batch_offset === 0 ) {
            $response['total_posts'] = $total_posts;
        }

        wp_send_json_success( $response );

    } catch ( Exception $e ) {
        // Generic error messages to prevent information disclosure
        $error_messages = [
            'unauthorized'    => __( 'Access denied', 'find-blocks-patterns-shortcodes' ),
            'rate_limit'      => __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ),
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

		// Rate limiting with IP tracking
		$rate_check = fbps_check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			throw new Exception( 'rate_limit' );
		}

		// Proper input handling with wp_unslash()
		$shortcode_name = isset( $_POST['shortcode_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shortcode_name'] ) ) : '';
		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [];
		$batch_offset = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;

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

		$search_result = fbps_get_posts_using_shortcode( $shortcode_name, $post_types, $batch_offset, 100 );

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

		if ( $batch_offset === 0 ) {
			$response['total_posts'] = $total_posts;
		}

		wp_send_json_success( $response );


	} catch ( Exception $e ) {
		// Generic error messages to prevent information disclosure
		$error_messages = [
			'unauthorized'  => __( 'Access denied', 'find-blocks-patterns-shortcodes' ),
			'rate_limit'    => __( 'Too many requests. Please wait.', 'find-blocks-patterns-shortcodes' ),
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
         * : Maximum number of posts to search (default: 1000)
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
            $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 1000;

            WP_CLI::log( sprintf( 'Searching for block: %s', $block_name ) );
            WP_CLI::log( sprintf( 'Post types: %s', implode( ', ', $post_types ) ) );

            // Search in batches
            $all_matches = [];
            $offset = 0;
            $batch_size = 100;

            do {
                $result = fbps_get_posts_using_block( $block_name, $post_types, $offset, $batch_size );
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

