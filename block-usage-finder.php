<?php
/**
 * Plugin Name: Block Usage Finder
 * Description: Scans posts and pages for Gutenberg block usage with a dynamic search field.
 * Version:     1.0
 * Author:      Matthew Cowan
 * Text Domain: block-usage-finder
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns an array of WP_Post objects that contain the specified block.
 */
function buf_get_posts_using_block( $block_name ) {
    $ids = get_posts([
        'post_type'      => [ 'post', 'page' ],
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ]);

    $matches = [];
    foreach ( $ids as $post_id ) {
        if ( has_block( $block_name, get_post( $post_id ) ) ) {
            $matches[] = get_post( $post_id );
        }
    }
    return $matches;
}

/**
 * Add admin menu page under "Block Usage".
 */
add_action( 'admin_menu', 'buf_add_menu' );
function buf_add_menu() {
    add_menu_page(
        __( 'Block Usage Finder', 'block-usage-finder' ),
        __( 'Block Usage', 'block-usage-finder' ),
        'manage_options',
        'block-usage-finder',
        'buf_render_admin_page'
    );
}

/**
 * Renders the admin page with a dynamic search field.
 */
function buf_render_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Block Usage Finder', 'block-usage-finder' ); ?></h1>
        <input type="text" id="buf-block-name" placeholder="<?php esc_attr_e( 'Enter block name, e.g. core/paragraph', 'block-usage-finder' ); ?>" style="width:300px;">
        <button id="buf-search-button" class="button button-primary"><?php esc_html_e( 'Search', 'block-usage-finder' ); ?></button>
        <div id="buf-search-results" style="margin-top:20px;"></div>
    </div>
    <script>
    (function($){
        $(function(){
            var timer;
            function searchBlock(block) {
                $('#buf-search-results').html('<p><?php esc_js( 'Searching...', 'block-usage-finder' ); ?></p>');
                $.post(ajaxurl, {
                    action:      'buf_search_block',
                    block_name:  block,
                    _ajax_nonce: '<?php echo wp_create_nonce( 'buf_search_nonce' ); ?>'
                }, function(response){
                    var html = '';
                    if ( response.success ) {
                        var data = response.data;
                        if ( data.length ) {
                            html += '<ul>';
                            data.forEach(function(item){
                                html += '<li><a href="'+ item.edit_link +'">'+ item.title +'</a> — <em>'+ item.type +'</em></li>';
                            });
                            html += '</ul>';
                        } else {
                            html = '<p><?php esc_js( 'No content found using that block.', 'block-usage-finder' ); ?></p>';
                        }
                    } else {
                        html = '<p><?php esc_js( 'Error:', 'block-usage-finder' ); ?> '+ response.data +'</p>';
                    }
                    $('#buf-search-results').html(html);
                });
            }
            $('#buf-search-button').on('click', function(){
                searchBlock( $('#buf-block-name').val() );
            });
            $('#buf-block-name').on('keyup', function(){
                clearTimeout(timer);
                timer = setTimeout(function(){
                    searchBlock( $('#buf-block-name').val() );
                }, 500);
            });
        });
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX handler for searching block usage.
 */
add_action( 'wp_ajax_buf_search_block', 'buf_ajax_search_block' );
function buf_ajax_search_block() {
    check_ajax_referer( 'buf_search_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Unauthorized', 'block-usage-finder' ) );
    }

    $block = sanitize_text_field( $_POST['block_name'] ?? '' );
    if ( empty( $block ) ) {
        wp_send_json_error( __( 'Empty block name', 'block-usage-finder' ) );
    }

    $posts = buf_get_posts_using_block( $block );
    $results = [];
    foreach ( $posts as $post ) {
        $results[] = [
            'id'        => $post->ID,
            'title'     => get_the_title( $post ),
            'edit_link' => get_edit_post_link( $post ),
            'type'      => $post->post_type,
        ];
    }

    wp_send_json_success( $results );
}
