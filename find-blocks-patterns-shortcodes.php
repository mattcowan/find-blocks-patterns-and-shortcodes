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
    <style>
        .fbps-search-field {
            margin-bottom: 15px;
        }
        .fbps-search-input,
        .fbps-block-dropdown,
        .fbps-pattern-dropdown,
        .fbps-shortcode-dropdown {
            max-width: 300px;
            width: 100%;
        }
        .fbps-post-types-select {
            max-width: 300px;
            width: 100%;
        }
        .fbps-search-actions {
            margin-top: 10px;
        }
        .fbps-results-container {
            margin-top: 20px;
        }
        .fbps-search-input:focus,
        .fbps-block-dropdown:focus,
        .fbps-pattern-dropdown:focus,
        .fbps-shortcode-dropdown:focus,
        .fbps-post-types-select:focus,
        #fbps-search-button:focus,
        #fbps-cancel-button:focus,
        #fbps-export-button:focus,
        #fbps-pattern-search-button:focus,
        #fbps-pattern-cancel-button:focus,
        #fbps-pattern-export-button:focus,
        #fbps-shortcode-search-button:focus,
        #fbps-shortcode-cancel-button:focus,
        #fbps-shortcode-export-button:focus {
            outline: 2px solid #0073aa;
            outline-offset: 2px;
        }
        #fbps-search-results a:focus {
            outline: 2px solid #0073aa;
            outline-offset: 1px;
        }
        .post-type-label {
            color: #646970;
            font-style: italic;
        }
        .fbps-results-count {
            font-weight: 600;
            margin-bottom: 0.5em;
        }
        .fbps-progress-container {
            margin-top: 20px;
        }
        .fbps-progress-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f0f0f1;
            border-top-color: #2271b1;
            border-radius: 50%;
            animation: fbps-spinner-rotation 0.8s linear infinite;
            margin-right: 10px;
            vertical-align: middle;
        }
        @keyframes fbps-spinner-rotation {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
        .fbps-progress-message {
            display: inline-block;
            vertical-align: middle;
        }
        .fbps-results-table th.sortable {
            cursor: pointer;
        }
        .fbps-results-table th.sortable a {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .fbps-results-table th.sortable .sorting-indicator {
            width: 10px;
            height: 4px;
            margin: 0 0 0 7px;
            display: inline-block;
            vertical-align: middle;
        }
        .fbps-results-table th.sortable.sorted .sorting-indicator:before {
            content: "";
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            display: inline-block;
        }
        .fbps-results-table th.sortable.sorted.asc .sorting-indicator:before {
            border-bottom: 4px solid #444;
        }
        .fbps-results-table th.sortable.sorted.desc .sorting-indicator:before {
            border-top: 4px solid #444;
        }
    </style>
    <script>
    (function($){
        $(function(){
            var timer;
            var currentNonce = '<?php echo esc_js( wp_create_nonce( 'fbps_search_nonce' ) ); ?>';
            var allResults = [];
            var allPatternResults = [];
            var allShortcodeResults = [];
            var currentSearch = null;
            var currentPatternSearch = null;
            var currentShortcodeSearch = null;

            // Handle block dropdown selection
            $('#fbps-block-dropdown').on('change', function() {
                var selectedBlock = $(this).val();
                if (selectedBlock) {
                    $('#fbps-block-name').val(selectedBlock);
                }
            });

            // Refresh nonce every 5 minutes (more frequent for long operations)
            setInterval(function() {
                $.post(ajaxurl, {
                    action: 'fbps_refresh_nonce'
                }, function(response) {
                    if (response.success && response.data && response.data.nonce) {
                        currentNonce = response.data.nonce;
                    }
                });
            }, 5 * 60 * 1000); // 5 minutes

            // Also refresh before each search if needed
            function ensureFreshNonce(callback) {
                $.post(ajaxurl, {
                    action: 'fbps_refresh_nonce'
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
                $(containerSelector + ' .fbps-results-table th.sortable a').off('click').on('click', function(e){
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
                var selected = $('#fbps-post-types').val();
                return selected && selected.length ? selected : ['post', 'page'];
            }

            function searchBlockBatch(block, postTypes, offset, accumulated) {
                offset = offset || 0;
                accumulated = accumulated || [];

                $.post(ajaxurl, {
                    action:      'fbps_search_block',
                    block_name:  block,
                    post_types:  postTypes,
                    batch_offset: offset,
                    _ajax_nonce: currentNonce
                }, function(response){
                    if (!response || typeof response !== 'object') {
                        displayError('<?php echo esc_js( __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ) ); ?>');
                        return;
                    }

                    if (!response.success) {
                        var errorMsg = response.data ? escapeHtml(String(response.data)) : '<?php echo esc_js( __( 'Unknown error', 'find-blocks-patterns-shortcodes' ) ); ?>';
                        displayError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !Array.isArray(data.results)) {
                        displayError('<?php echo esc_js( __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ) ); ?>');
                        return;
                    }

                    accumulated = accumulated.concat(data.results);
                    allResults = accumulated;

                    // Store total_posts from first batch
                    if (data.total_posts) {
                        window.fbps_total_posts = data.total_posts;
                    }

                    // Update progress
                    updateProgress(accumulated.length);

                    // Display current results
                    displayResults(accumulated, !data.has_more);

                    // Continue batching if more results
                    if (data.has_more && currentSearch === block) {
                        searchBlockBatch(block, postTypes, data.next_offset, accumulated);
                    } else {
                        $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
                        $('#fbps-cancel-button').hide();
                        $('#fbps-progress').hide();
                        if (accumulated.length > 0) {
                            $('#fbps-export-button').show();
                        }
                    }
                }).fail(function() {
                    displayError('<?php echo esc_js( __( 'Network error. Please try again.', 'find-blocks-patterns-shortcodes' ) ); ?>');
                });
            }

            function updateProgress(count) {
                var html = '<div class="fbps-progress-spinner" role="status" aria-label="<?php echo esc_js( __( 'Searching', 'find-blocks-patterns-shortcodes' ) ); ?>"></div>';
                html += '<span class="fbps-progress-message">';
                html += '<?php echo esc_js( __( 'Searching...', 'find-blocks-patterns-shortcodes' ) ); ?> ' + count + ' <?php echo esc_js( __( 'results found so far', 'find-blocks-patterns-shortcodes' ) ); ?>';
                html += '</span>';
                $('#fbps-progress').html(html);
            }

            function displayResults(data, isComplete) {
                var html = '';
                if (data.length) {
                    html += '<p class="fbps-results-count" aria-live="polite">';
                    html += data.length + ' ' + (data.length === 1 ? '<?php echo esc_js( __( 'result', 'find-blocks-patterns-shortcodes' ) ); ?>' : '<?php echo esc_js( __( 'results', 'find-blocks-patterns-shortcodes' ) ); ?>');
                    if (!isComplete) {
                        html += ' <?php echo esc_js( __( 'found so far...', 'find-blocks-patterns-shortcodes' ) ); ?>';
                    } else {
                        html += ' <?php echo esc_js( __( 'found', 'find-blocks-patterns-shortcodes' ) ); ?>';
                    }
                    html += '</p>';
                    html += '<table class="wp-list-table widefat fixed striped fbps-results-table">';
                    html += '<thead><tr>';
                    html += '<th class="sortable" data-column="title"><a href="#"><span><?php echo esc_js( __( 'Title', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable" data-column="type"><a href="#"><span><?php echo esc_js( __( 'Type', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span><?php echo esc_js( __( 'Date', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th><?php echo esc_js( __( 'Actions', 'find-blocks-patterns-shortcodes' ) ); ?></th>';
                    html += '</tr></thead><tbody>';
                    data.forEach(function(item){
                        if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                            html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                            html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                            html += '<td>'+ escapeHtml(item.type) +'</td>';
                            html += '<td>'+ formatDate(item.date) +'</td>';
                            html += '<td>';
                            html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'View', 'find-blocks-patterns-shortcodes' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank"><?php echo esc_js( __( 'View', 'find-blocks-patterns-shortcodes' ) ); ?></a> ';
                            html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'Edit', 'find-blocks-patterns-shortcodes' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'"><?php echo esc_js( __( 'Edit', 'find-blocks-patterns-shortcodes' ) ); ?></a>';
                            html += '</td>';
                            html += '</tr>';
                        }
                    });
                    html += '</tbody></table>';
                } else if (isComplete) {
                    html = '<p><?php echo esc_js( __( 'No content found using that block.', 'find-blocks-patterns-shortcodes' ) ); ?></p>';
                }
                $('#fbps-search-results').html(html).attr('tabindex', '-1').focus();
                initTableSort('#fbps-search-results');
            }

            function displayError(message) {
                var html = '<div role="alert" class="notice notice-error"><p><strong><?php echo esc_js( __( 'Error:', 'find-blocks-patterns-shortcodes' ) ); ?></strong> '+ message +'</p></div>';
                $('#fbps-search-results').html(html);
                $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#fbps-cancel-button').hide();
                $('#fbps-progress').hide();
            }

            function searchBlock(block) {
                currentSearch = block;
                allResults = [];
                $('#fbps-export-button').hide();
                $('#fbps-search-button').prop('disabled', true).attr('aria-busy', 'true');
                $('#fbps-cancel-button').show();
                // Clear all result containers
                $('#fbps-search-results').empty();
                $('#fbps-pattern-search-results').empty();
                $('#fbps-shortcode-search-results').empty();
                $('#fbps-progress').show();
                updateProgress(0);

                ensureFreshNonce(function() {
                    searchBlockBatch(block, getSelectedPostTypes(), 0, []);
                });
            }

            $('#fbps-search-button').on('click', function(){
                searchBlock( $('#fbps-block-name').val() );
            });

            // Cancel block search
            $('#fbps-cancel-button').on('click', function(){
                currentSearch = null;
                $('#fbps-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#fbps-cancel-button').hide();
                $('#fbps-progress').hide();
                var html = '<div role="alert" class="notice notice-warning"><p><?php echo esc_js( __( 'Search cancelled.', 'find-blocks-patterns-shortcodes' ) ); ?></p></div>';
                $('#fbps-search-results').html(html);
            });

            // Unified CSV Export - detects which search type has results
            $('#fbps-export-button').on('click', function(){
                var csv = 'Title,Type,Date,View Link\n';
                var filename = '';
                var results = [];

                // Determine which search has results and use appropriate data
                if (allResults.length > 0) {
                    results = allResults;
                    filename = 'block-usage-' + $('#fbps-block-name').val().replace(/[^a-z0-9]/gi, '-') + '.csv';
                } else if (allPatternResults.length > 0) {
                    results = allPatternResults;
                    var patternName = $('#fbps-pattern-dropdown option:selected').text().replace(/[^a-z0-9]/gi, '-');
                    filename = 'pattern-usage-' + patternName + '.csv';
                } else if (allShortcodeResults.length > 0) {
                    results = allShortcodeResults;
                    var shortcodeName = $('#fbps-shortcode-dropdown').val().replace(/[^a-z0-9]/gi, '-');
                    filename = 'shortcode-usage-' + shortcodeName + '.csv';
                }

                // Build CSV from results
                results.forEach(function(item){
                    csv += '"' + sanitizeCsvValue(item.title) + '",';
                    csv += '"' + sanitizeCsvValue(item.type) + '",';
                    csv += '"' + sanitizeCsvValue(item.date) + '",';
                    csv += '"' + sanitizeCsvValue(item.view_link) + '"\n';
                });

                // Download CSV
                var blob = new Blob([csv], { type: 'text/csv' });
                var url = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();
                window.URL.revokeObjectURL(url);
            });

            // Add Enter key support
            $('#fbps-block-name').on('keypress', function(e){
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    clearTimeout(timer);
                    searchBlock( $(this).val() );
                }
            });

            $('#fbps-block-name').on('keyup', function(e){
                if (e.which === 13) return; // Skip Enter key for debounced search
                clearTimeout(timer);
                timer = setTimeout(function(){
                    searchBlock( $('#fbps-block-name').val() );
                }, 500);
            });

            // ========== PATTERN SEARCH FUNCTIONS ==========

            function getSelectedPatternPostTypes() {
                var selected = $('#fbps-pattern-post-types').val();
                return selected && selected.length ? selected : ['post', 'page'];
            }

            function searchPatternBatch(patternId, postTypes, offset, accumulated) {
                offset = offset || 0;
                accumulated = accumulated || [];

                $.post(ajaxurl, {
                    action:      'fbps_search_pattern',
                    pattern_id:  patternId,
                    post_types:  postTypes,
                    batch_offset: offset,
                    _ajax_nonce: currentNonce
                }, function(response){
                    if (!response || typeof response !== 'object') {
                        displayPatternError('<?php echo esc_js( __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ) ); ?>');
                        return;
                    }

                    if (!response.success) {
                        var errorMsg = response.data ? escapeHtml(String(response.data)) : '<?php echo esc_js( __( 'Unknown error', 'find-blocks-patterns-shortcodes' ) ); ?>';
                        displayPatternError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !Array.isArray(data.results)) {
                        displayPatternError('<?php echo esc_js( __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ) ); ?>');
                        return;
                    }

                    accumulated = accumulated.concat(data.results);
                    allPatternResults = accumulated;

                    // Store total_posts from first batch
                    if (data.total_posts) {
                        window.fbps_pattern_total_posts = data.total_posts;
                    }

                    // Update progress
                    updatePatternProgress(accumulated.length);

                    // Display current results
                    displayPatternResults(accumulated, !data.has_more);

                    // Continue batching if more results
                    if (data.has_more && currentPatternSearch === patternId) {
                        searchPatternBatch(patternId, postTypes, data.next_offset, accumulated);
                    } else {
                        $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                        $('#fbps-pattern-cancel-button').hide();
                        $('#fbps-pattern-progress').hide();
                        if (accumulated.length > 0) {
                            $('#fbps-pattern-export-button').show();
                        }
                    }
                }).fail(function() {
                    displayPatternError('<?php echo esc_js( __( 'Network error. Please try again.', 'find-blocks-patterns-shortcodes' ) ); ?>');
                });
            }

            function updatePatternProgress(count) {
                var html = '<div class="fbps-progress-spinner" role="status" aria-label="<?php echo esc_js( __( 'Searching', 'find-blocks-patterns-shortcodes' ) ); ?>"></div>';
                html += '<span class="fbps-progress-message">';
                html += '<?php echo esc_js( __( 'Searching...', 'find-blocks-patterns-shortcodes' ) ); ?> ' + count + ' <?php echo esc_js( __( 'results found so far', 'find-blocks-patterns-shortcodes' ) ); ?>';
                html += '</span>';
                $('#fbps-pattern-progress').html(html);
            }

            function displayPatternResults(data, isComplete) {
                var html = '';
                if (data.length) {
                    html += '<p class="fbps-results-count" aria-live="polite">';
                    html += data.length + ' ' + (data.length === 1 ? '<?php echo esc_js( __( 'result', 'find-blocks-patterns-shortcodes' ) ); ?>' : '<?php echo esc_js( __( 'results', 'find-blocks-patterns-shortcodes' ) ); ?>');
                    if (!isComplete) {
                        html += ' <?php echo esc_js( __( 'found so far...', 'find-blocks-patterns-shortcodes' ) ); ?>';
                    } else {
                        html += ' <?php echo esc_js( __( 'found', 'find-blocks-patterns-shortcodes' ) ); ?>';
                    }
                    html += '</p>';
                    html += '<table class="wp-list-table widefat fixed striped fbps-results-table">';
                    html += '<thead><tr>';
                    html += '<th class="sortable" data-column="title"><a href="#"><span><?php echo esc_js( __( 'Title', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable" data-column="type"><a href="#"><span><?php echo esc_js( __( 'Type', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span><?php echo esc_js( __( 'Date', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th><?php echo esc_js( __( 'Actions', 'find-blocks-patterns-shortcodes' ) ); ?></th>';
                    html += '</tr></thead><tbody>';
                    data.forEach(function(item){
                        if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                            html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                            html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                            html += '<td>'+ escapeHtml(item.type) +'</td>';
                            html += '<td>'+ formatDate(item.date) +'</td>';
                            html += '<td>';
                            html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'View', 'find-blocks-patterns-shortcodes' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank"><?php echo esc_js( __( 'View', 'find-blocks-patterns-shortcodes' ) ); ?></a> ';
                            html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'Edit', 'find-blocks-patterns-shortcodes' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'"><?php echo esc_js( __( 'Edit', 'find-blocks-patterns-shortcodes' ) ); ?></a>';
                            html += '</td>';
                            html += '</tr>';
                        }
                    });
                    html += '</tbody></table>';
                } else if (isComplete) {
                    html = '<p><?php echo esc_js( __( 'No content found using that synced pattern.', 'find-blocks-patterns-shortcodes' ) ); ?></p>';
                }
                $('#fbps-pattern-search-results').html(html).attr('tabindex', '-1').focus();
                initTableSort('#fbps-pattern-search-results');
            }

            function displayPatternError(message) {
                var html = '<div role="alert" class="notice notice-error"><p><strong><?php echo esc_js( __( 'Error:', 'find-blocks-patterns-shortcodes' ) ); ?></strong> '+ message +'</p></div>';
                $('#fbps-pattern-search-results').html(html);
                $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#fbps-pattern-cancel-button').hide();
                $('#fbps-pattern-progress').hide();
            }

            function searchPattern(patternId) {
                if (!patternId) {
                    displayPatternError('<?php echo esc_js( __( 'Please select a synced pattern', 'find-blocks-patterns-shortcodes' ) ); ?>');
                    return;
                }

                currentPatternSearch = patternId;
                allPatternResults = [];
                $('#fbps-export-button').hide();
                $('#fbps-pattern-search-button').prop('disabled', true).attr('aria-busy', 'true');
                $('#fbps-pattern-cancel-button').show();
                // Clear all result containers
                $('#fbps-search-results').empty();
                $('#fbps-pattern-search-results').empty();
                $('#fbps-shortcode-search-results').empty();
                $('#fbps-pattern-progress').show();
                updatePatternProgress(0);

                ensureFreshNonce(function() {
                    searchPatternBatch(patternId, getSelectedPatternPostTypes(), 0, []);
                });
            }

            // Pattern search button handler
            $('#fbps-pattern-search-button').on('click', function(){
                searchPattern( $('#fbps-pattern-dropdown').val() );
            });

            // Cancel pattern search
            $('#fbps-pattern-cancel-button').on('click', function(){
                currentPatternSearch = null;
                $('#fbps-pattern-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#fbps-pattern-cancel-button').hide();
                $('#fbps-pattern-progress').hide();
                var html = '<div role="alert" class="notice notice-warning"><p><?php echo esc_js( __( 'Search cancelled.', 'find-blocks-patterns-shortcodes' ) ); ?></p></div>';
                $('#fbps-pattern-search-results').html(html);
            });

            // ========== SHORTCODE SEARCH FUNCTIONS ==========

            function getSelectedShortcodePostTypes() {
                var selected = $('#fbps-shortcode-post-types').val();
                return selected && selected.length ? selected : ['post', 'page'];
            }

            function searchShortcodeBatch(shortcodeName, postTypes, offset, accumulated) {
                offset = offset || 0;
                accumulated = accumulated || [];

                $.post(ajaxurl, {
                    action:         'fbps_search_shortcode',
                    shortcode_name: shortcodeName,
                    post_types:     postTypes,
                    batch_offset:   offset,
                    _ajax_nonce:    currentNonce
                }, function(response){
                    if (!response || typeof response !== 'object') {
                        displayShortcodeError('<?php echo esc_js( __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ) ); ?>');
                        return;
                    }

                    if (!response.success) {
                        var errorMsg = response.data ? escapeHtml(String(response.data)) : '<?php echo esc_js( __( 'Unknown error', 'find-blocks-patterns-shortcodes' ) ); ?>';
                        displayShortcodeError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !Array.isArray(data.results)) {
                        displayShortcodeError('<?php echo esc_js( __( 'Invalid response format', 'find-blocks-patterns-shortcodes' ) ); ?>');
                        return;
                    }

                    accumulated = accumulated.concat(data.results);
                    allShortcodeResults = accumulated;

                    // Store total_posts from first batch
                    if (data.total_posts) {
                        window.fbps_shortcode_total_posts = data.total_posts;
                    }

                    // Update progress
                    updateShortcodeProgress(accumulated.length);

                    // Display current results
                    displayShortcodeResults(accumulated, !data.has_more);

                    // Continue batching if more results
                    if (data.has_more && currentShortcodeSearch === shortcodeName) {
                        searchShortcodeBatch(shortcodeName, postTypes, data.next_offset, accumulated);
                    } else {
                        $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
                        $('#fbps-shortcode-cancel-button').hide();
                        $('#fbps-shortcode-progress').hide();
                        if (accumulated.length > 0) {
                            $('#fbps-shortcode-export-button').show();
                        }
                    }
                }).fail(function() {
                    displayShortcodeError('<?php echo esc_js( __( 'Network error. Please try again.', 'find-blocks-patterns-shortcodes' ) ); ?>');
                });
            }

            function updateShortcodeProgress(count) {
                var html = '<div class="fbps-progress-spinner" role="status" aria-label="<?php echo esc_js( __( 'Searching', 'find-blocks-patterns-shortcodes' ) ); ?>"></div>';
                html += '<span class="fbps-progress-message">';
                html += '<?php echo esc_js( __( 'Searching...', 'find-blocks-patterns-shortcodes' ) ); ?> ' + count + ' <?php echo esc_js( __( 'results found so far', 'find-blocks-patterns-shortcodes' ) ); ?>';
                html += '</span>';
                $('#fbps-shortcode-progress').html(html);
            }

            function displayShortcodeResults(data, isComplete) {
                var html = '';
                if (data.length) {
                    html += '<p class="fbps-results-count" aria-live="polite">';
                    html += data.length + ' ' + (data.length === 1 ? '<?php echo esc_js( __( 'result', 'find-blocks-patterns-shortcodes' ) ); ?>' : '<?php echo esc_js( __( 'results', 'find-blocks-patterns-shortcodes' ) ); ?>');
                    if (!isComplete) {
                        html += ' <?php echo esc_js( __( 'found so far...', 'find-blocks-patterns-shortcodes' ) ); ?>';
                    } else {
                        html += ' <?php echo esc_js( __( 'found', 'find-blocks-patterns-shortcodes' ) ); ?>';
                    }
                    html += '</p>';
                    html += '<table class="wp-list-table widefat fixed striped fbps-results-table">';
                    html += '<thead><tr>';
                    html += '<th class="sortable" data-column="title"><a href="#"><span><?php echo esc_js( __( 'Title', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable" data-column="type"><a href="#"><span><?php echo esc_js( __( 'Type', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th class="sortable sorted desc" data-column="date"><a href="#"><span><?php echo esc_js( __( 'Date', 'find-blocks-patterns-shortcodes' ) ); ?></span><span class="sorting-indicator"></span></a></th>';
                    html += '<th><?php echo esc_js( __( 'Actions', 'find-blocks-patterns-shortcodes' ) ); ?></th>';
                    html += '</tr></thead><tbody>';
                    data.forEach(function(item){
                        if (item && item.edit_link && item.view_link && item.title && item.type && item.date) {
                            html += '<tr data-title="'+ escapeHtml(item.title) +'" data-type="'+ escapeHtml(item.type) +'" data-date="'+ escapeHtml(item.date) +'">';
                            html += '<td><strong>'+ escapeHtml(item.title) +'</strong></td>';
                            html += '<td>'+ escapeHtml(item.type) +'</td>';
                            html += '<td>'+ formatDate(item.date) +'</td>';
                            html += '<td>';
                            html += '<a href="'+ escapeHtml(item.view_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'View', 'find-blocks-patterns-shortcodes' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'" target="_blank"><?php echo esc_js( __( 'View', 'find-blocks-patterns-shortcodes' ) ); ?></a> ';
                            html += '<a href="'+ escapeHtml(item.edit_link) +'" class="button button-small" aria-label="<?php echo esc_js( __( 'Edit', 'find-blocks-patterns-shortcodes' ) ); ?> '+ escapeHtml(item.type) +': '+ escapeHtml(item.title) +'"><?php echo esc_js( __( 'Edit', 'find-blocks-patterns-shortcodes' ) ); ?></a>';
                            html += '</td>';
                            html += '</tr>';
                        }
                    });
                    html += '</tbody></table>';
                } else if (isComplete) {
                    html = '<p><?php echo esc_js( __( 'No content found using that shortcode.', 'find-blocks-patterns-shortcodes' ) ); ?></p>';
                }
                $('#fbps-shortcode-search-results').html(html).attr('tabindex', '-1').focus();
                initTableSort('#fbps-shortcode-search-results');
            }

            function displayShortcodeError(message) {
                var html = '<div role="alert" class="notice notice-error"><p><strong><?php echo esc_js( __( 'Error:', 'find-blocks-patterns-shortcodes' ) ); ?></strong> '+ message +'</p></div>';
                $('#fbps-shortcode-search-results').html(html);
                $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#fbps-shortcode-cancel-button').hide();
                $('#fbps-shortcode-progress').hide();
            }

            function searchShortcode(shortcodeName) {
                if (!shortcodeName) {
                    displayShortcodeError('<?php echo esc_js( __( 'Please select a shortcode', 'find-blocks-patterns-shortcodes' ) ); ?>');
                    return;
                }

                currentShortcodeSearch = shortcodeName;
                allShortcodeResults = [];
                $('#fbps-export-button').hide();
                $('#fbps-shortcode-search-button').prop('disabled', true).attr('aria-busy', 'true');
                $('#fbps-shortcode-cancel-button').show();
                // Clear all result containers
                $('#fbps-search-results').empty();
                $('#fbps-pattern-search-results').empty();
                $('#fbps-shortcode-search-results').empty();
                $('#fbps-shortcode-progress').show();
                updateShortcodeProgress(0);

                ensureFreshNonce(function() {
                    searchShortcodeBatch(shortcodeName, getSelectedShortcodePostTypes(), 0, []);
                });
            }

            // Shortcode search button handler
            $('#fbps-shortcode-search-button').on('click', function(){
                searchShortcode( $('#fbps-shortcode-dropdown').val() );
            });

            // Cancel shortcode search
            $('#fbps-shortcode-cancel-button').on('click', function(){
                currentShortcodeSearch = null;
                $('#fbps-shortcode-search-button').prop('disabled', false).attr('aria-busy', 'false');
                $('#fbps-shortcode-cancel-button').hide();
                $('#fbps-shortcode-progress').hide();
                var html = '<div role="alert" class="notice notice-warning"><p><?php echo esc_js( __( 'Search cancelled.', 'find-blocks-patterns-shortcodes' ) ); ?></p></div>';
                $('#fbps-shortcode-search-results').html(html);
            });
        });
    })(jQuery);
    </script>
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

